# MIGRATION — pre-4.2 to Containerist 4.2

*How to bring an old Containerist-adjacent site (or pre-Stacker-split 4.x code) into conformance with the current briefing. First written 2026-04-21 from the Daily Reader port; updated as new ports surface new patterns.*

This is a field manual, not a spec. For the authoritative shape of 4.2 read `docs/containerist.md`. For rationale read `briefing-detailed.md`. This document assumes you've read both and are now staring at a module that doesn't look like them.

---

## When you need this

You are porting a site (or a single module) that was written before one of the following landed:

- **adapter / Core split** (pillar 8 enforced structurally, not by discipline).
- **`@in` schema** (declared inputs, `array_intersect_key` against schema, no superglobals in mods).
- **CTN control-flow blocks** (`redirect`, `error`, `content-type`).
- **Static container auto-registration** (flat id-space via filename stem).

Symptoms that you're looking at pre-4.2 code, any one is enough:

- A mod reads `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`, `$request->*`, or `$module_dir`.
- A mod calls `exit`, `die`, `header('Location: …')`, `http_response_code()`, or `headers_sent()`.
- A mod has no `// @in:` header on line 2.
- A mod ends with `?>` followed by raw template text below the PHP close tag.
- A stack file has frontmatter logic, computed routes, or conditional includes.
- Static content files live at arbitrary paths instead of under `containers/`.

---

## The five recurring patterns

Every pre-4.2 mod you port will hit some subset of these. They are roughly ordered by how often they appear.

### Pattern 1 — Superglobal reads → `@in` declaration

**Before.** Mod compensates for missing `@in` by reading the realm directly:

```php
<?php
$date = $_GET['date'] ?? $request->first ?? date('Y-m-d');
```

**After.** Declare the input on line 2; the adapter populates it from whatever realm (URL parts, `@args`, query string, POST, CLI args, in-process call). The mod never knows which realm it's running in.

```php
<?php
// @in: date (optional)

$date = $date ?? date('Y-m-d');
```

**Why.** The Core's `array_intersect_key($args, $schema)` drops any key the mod hasn't declared. A mod without an `@in` header receives `$args = []` — which is why old code reached for superglobals. Declaring `@in` makes the contract visible and portable across realms.

**Edge cases.**
- A computed default (today, uuid, session-derived) **cannot** live in the `@in` header: `(default: today)` stores the literal string `"today"`, not today's date. Declare the arg `(optional)` and compute on line 1 of the body.
- If the mod genuinely needs nothing, write `// @in:` (empty header) or `// @in: (nothing)` — the linter treats both as "explicit zero inputs" and stays quiet.
- `$C` (the Core instance) is always in scope without declaration. Do not declare it in `@in`.

### Pattern 2 — `header('Location:') + exit` → `CTN: redirect` block

**Before.** The mod seizes the HTTP realm directly:

```php
header('Location: /' . $date);
exit;
```

**After.** Emit a typed control-flow block. The adapter inspects the combined stack CTN, finds a `redirect` block, and handles the 302.

```php
echo "CTN: redirect\nto: /{$date}\n---\n";
```

**Why.** Mods must not touch the realm. A mod in 4.2 only emits CTN; the adapter turns CTN into realm-appropriate actions. The `redirect` block is contract-bound: `to` (required), `status` (optional, default 302). See `briefing-detailed.md` § 10.1.

**Edge cases.**
- After emitting the redirect, `return;` (bare) to short-circuit. Don't `exit;` — that kills the adapter. A bare return exits the `ob_start`-wrapped include cleanly.
- The redirect block only works through an implementation that understands control-flow blocks. The cli adapter may ignore it (prints the CTN); the web adapter acts on it. That is correct.
- If multiple blocks appear before the redirect, they are parsed but their rendered output is discarded when the adapter redirects. Don't rely on side-effect ordering.

### Pattern 3 — `echo "…error…"; exit;` → `CTN: error` block

**Before.** Ad-hoc error emission that kills the adapter:

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  echo "CTN: standard\n---\nInvalid date format\n";
  exit;
}
```

**After.** A typed error block with HTTP status; `return;` not `exit;`. The adapter sets the HTTP status and still renders the body so the user sees the message.

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  echo "CTN: error\ncode: 400\n---\nInvalid date format. Expected YYYY-MM-DD.\n";
  return;
}
```

**Why.** Same as Pattern 2 — mods emit typed blocks; the adapter interprets. The `error` block is contract-bound: `code` (required, 4xx/5xx), `message` (required), `detail` (optional). `briefing-detailed.md` § 10.1.

**Edge cases.**
- `code: 404` for missing-resource, `code: 400` for bad input, `code: 500` for "a dependency I rely on is missing" (e.g. config container absent).
- The error message is rendered in the HTML body. Don't put stack traces or internal paths there.
- Other containers in the same stack still run after an error block. That's intentional — the footer, nav, etc. still render. If you want *nothing else to render*, emit `CTN: redirect` to an error page instead.

### Pattern 4 — Trailing `?>` template → inline `echo` / `print mustache()`

**Before.** The mod ends with a close tag, then raw template text that PHP emits on its way out:

```php
exit;

?>
status: success
time: <?= date('Y-m-d H:i:s') ?>
---
Created digest for <?= $date ?>
```

**After.** Either inline the template as an `echo`, or — if the template is non-trivial — keep a separate `.tpl.txt` and render via `mustache()`. Do not mix PHP close tags with literal CTN output.

```php
print mustache(
  file_get_contents(__DIR__ . '/foo.tpl.txt'),
  array('date' => $date, 'status' => 'success')
);
```

**Why.** The Core wraps every mod invocation in `ob_start()` / `ob_get_clean()`. Any raw text after `?>` is captured into the mod's output buffer. This is a quiet footgun:

- If the close-tag block is after an `exit;` that *does* fire, it never runs — the template is dead code that looks alive.
- If the close-tag block is reached, it dumps *unescaped* template text into the CTN stream, bypassing any type discipline.
- A `return <value>;` at the top level has the same shape of failure: the value is discarded silently by `ob_get_clean` and callers see empty output.

The rule from the authoring checklist: **emit with `print` / `echo`; never `return $value`; and never leave raw template text below `?>`**. Use bare `return;` for early exit.

**Edge cases.**
- `$module_dir` is pre-4.2. Use `__DIR__` inside the mod to resolve sibling template paths.
- Mustache is loaded by `config.php` at boot; `mustache()` is a global helper in `lib/common/tools.php`. Same for `yaml_read()`. You can rely on both at mod-body scope.

### Pattern 5 — Ad-hoc file paths → static container auto-registration

**Before.** The mod reaches into the filesystem with raw paths:

```php
$digest_path = "containers/digests/$year/digest-$date.txt";
if (file_exists($digest_path)) {
  $content = file_get_contents($digest_path);
  // regex-parse $content to pull out what we need …
}
```

**After.** Use `$C->ctn(<id>)` against the auto-registered static containers. The Core walks `containers/**/*.txt` recursively and keys each file by filename-stem. Then parse with `$C->parse_ctn()`.

```php
$content = $C->ctn('digest-' . $date);
if ($content === false) { /* emit CTN: error 404 */ return; }

foreach ($C->parse_ctn($content) as $block) {
  if (($block['fields']['section'] ?? null) === 'sources') { /* ... */ }
}
```

**Why.** Flat id-space is pillar 7. The subfolder (`digests/2026/`) is pure organization — the id is the filename stem. This means:

- Consumers of `digest-2026-04-20` don't need to know where on disk it lives.
- Shadowing works: a mod named `digest-2026-04-20` would take precedence (invisible to callers, visible in the registry).
- Regex-scraping a CTN file is always wrong. `parse_ctn` returns a clean block tree.

**Edge cases.**
- `register_containers()` only walks `.txt` files. Other extensions are invisible to `$C->ctn()` and must be read explicitly.
- The id collision space is flat across subfolders. `containers/a/foo.txt` and `containers/b/foo.txt` both register as id `foo`; the second to register wins. Organize your subfolders so stems are globally unique within `containers/`.
- Writing a new file to `containers/**/*.txt` makes it reachable on the *next* Core construction, not the current request. Cron-built content is fine; read-your-own-writes within a single request is not.

---

## The per-module checklist

Use this order on every mod you port. Doing step 2 before step 1 tempts you to smuggle globals through.

1. **Add the `@in` header** on line 2. List every input the mod body reads. If you're not sure what it reads, grep the body for `$` first.
2. **Delete superglobal reads.** Replace `$_GET['x']`, `$request->foo`, `$module_dir` with the declared input, `__DIR__`, etc.
3. **Add defaults.** For inputs that have a sensible fallback, declare `(optional)` and `$x = $x ?? default_expr();` on the next line. For literal defaults, use `(default: <literal>)` directly in `@in`.
4. **Replace `exit` / `die` with typed blocks + `return;`.** `exit` is a realm-level kill switch. Mods don't have one.
5. **Replace `header(…)` calls.** `Location:` → `CTN: redirect`. Error status → `CTN: error`. Non-HTML → `CTN: content-type`.
6. **Inline or externalize templates.** No raw text below `?>`. Either `print mustache(file_get_contents(…), $vars);` or just `echo`.
7. **Use `$C->ctn()` and `$C->parse_ctn()` instead of file reads.** Unless the file is genuinely outside the container registry (e.g. an on-disk cache), reach through the Core.
8. **Add the `@in` header to the linter's expectations.** Run `tools/lint-in.php` (or whichever wrapper the target repo uses); fix warnings until silent.
9. **Smoke.** CLI first (`./ctnr <mod> key=val`), then the stack that invokes it via a realm. CLI surfaces `@in` schema violations instantly; realm surfaces control-flow block handling.

---

## The per-stack checklist

Stacks are smaller, but the failure modes are sneakier because they're silent.

1. **Frontmatter is a pure binding table.** `@args: name = {{N}}` or `name = "literal"`. No computation. No `date()` calls in a stack. The mods default to today themselves. (STACK-SPEC 2.0 uses `{{N}}` / `{{name}}` Mustache-style refs; pre-2.0 sites with `$N` are migrated via one `sed` pass.)
2. **Body is a flat list of container invocations**, one per line. A line is `<name>` or `<name>?k=v&k2=v2…` (per-line args per STACK-SPEC §7.3) — the per-line-args form lets one mod be invoked multiple times with different args in the same stack. Comments allowed with `#`. A stack line may also be `htmx: <name>` (optionally with per-line args) — that emits a placeholder block and the fragment loads client-side.
3. **Index route is `index.txt`**, not `/`. The Request matcher maps empty URL parts to `['index']`, so `stacks/index.txt` handles `/`.
4. **Wildcard files must use literal `*` in the filename** — `*.txt`, `*--refresh.txt`. Don't be clever; the matcher reads them as patterns.
5. **Ensure a trailing newline.** A stack body ending mid-line will miss the last container because the parser splits on `\n`.

---

## Config and layout

A few site-level items that don't fit the per-file checklists:

- **`config.php` is paths + lib includes, nothing else.** No admin state, no session, no domain logic. `DOMAIN`, `SITE_URL`, `LIB`, `ROOT_DIR`, `MODULES_DIR`, `CONTAINERS_DIR`, `STACKS_DIR`, `SKIN_DIR`, `TRACE_ENABLED`. If your old `config.php` has more, it has probably migrated realm-state into the Core.
- **Per-module READMEs are expected for non-trivial modules** (see `briefing-detailed.md` on per-module READMEs). Keep them scoped: mod inventory, storage conventions, local conventions that aren't derivable from the code. Don't restate the pillars.
- **Skin directory is untouched by the port.** A pre-4.2 site's `skin/<type>.{html,css}` pairs carry over as-is; the type → pair contract is stable across 4.x.

---

## What to avoid

These are drift patterns LLMs (including future sessions of me) will reach for when porting pre-4.2 code. Each one looks like progress from inside the priors and is damage in the system.

- **Adding a "request helper" wrapper** around `$_GET`/`$_POST` to smooth the port. The fix is `@in`, not a new abstraction.
- **Promoting a mod to a "service" or "base class"** because several mods share a helper. Stay in plain PHP files. Shared logic becomes a plain function in `lib/`, not an abstract mod base.
- **Inventing new CTN block types to carry control-flow** when `redirect` / `error` / `content-type` already cover the case. Three is enough; a fourth needs a pillar-level justification.
- **Adding stack-level computation** to save a line in a mod. Stacks stay dumb. Mods stay boring.
- **Composer-ifying the port.** If a site reaches for Composer while being ported to 4.2, the port is in the wrong direction. (Briefing § 9.)

---

## Author preferences (Konstantin's sites)

Cross-site preferences observed during ports. These aren't framework rules — another author's sites might legitimately do otherwise — but when porting any site under this author's maintenance, honor them unless the user explicitly redirects.

- **No logic inside stack definitions.** Stated verbatim during the Daily Reader port: *"no logic inside stack definitions."* Stacks stay purely declarative — `@args: name = {{N}}` or literals, nothing else. If a mod in the stack needs a computed default (today's date, a generated uuid, session-derived value), the mod declares `@in: x (optional)` and computes on line 1 of its body. Never `@args: date = today()` or similar. Per-line args (STACK-SPEC §7.3) are still declarative: they're fixed overrides layered on a specific invocation, not computation.
- **Templates stay in separate `.tpl.txt` files.** Stated verbatim: *"keep the digest-title.tpl.txt. i love to have templates and logic separated."* For mods that emit structured CTN with repeating patterns (nav items, titles, lists), keep the template in a sibling `.tpl.txt` and render via `mustache(file_get_contents(__DIR__ . '/name.tpl.txt'), $vars)`. Do not inline the template as a heredoc or string concat to "save a file." Exception: a single-line CTN block with one or two interpolations (`echo "CTN: redirect\nto: /{$date}\n---\n";`) is fine inline.
- **Propose before large design moves.** On ambiguous scope (nav consolidation, stack-line arg syntax, site routing), the user expects to be consulted with options rather than presented with a finished implementation. Use the Plan agent or a short inline "A vs B vs C" when a design call isn't obvious.

---

## Worked examples

- **Daily Reader** (2026-04-21) — `reader.konnexus.net`. All six `modules/dailyreader/*.ctn.php` ported in a single pass. Per-site notes in `sites/reader.konnexus.net.md`. Session log: `logs/2026-04-21-dailyreader-port.md`.

*Add new examples here as they land. Each entry: date, repo path, what was interesting. Per-site operational notes (infra, deploy gotchas) go under `sites/<domain>.md`.*
