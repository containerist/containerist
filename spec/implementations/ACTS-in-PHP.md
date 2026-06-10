# ACTS-in-PHP — PHP Implementation Guide for Acts

**Status:** Informative. `ACTS-SPEC.md` is authority; on conflict the spec wins.

This sidecar collects the PHP-specific conventions that previously lived in seven separate `acts/*.php` file headers and the renderer's dispatch-loop doc-comment (`core/renderers/dispatch.php`). Future act-file headers should reference this document plus their per-act `ACTS-SPEC.md §5.x` entry, and add only what is genuinely act-specific.

Acts run only when the **html renderer** assembles a place (full render: PRG, dispatcher selection, acts dispatch, page-wrap). The **raw renderer** (`.ctn` / `.raw` / `.htmx`-mod) skips act-dispatch (see §"Raw-renderer bypass" below).

---

## Target environment

- **PHP** 7.4+. No autoloader, no Composer.
- **HTTP layer:** Apache or `php -S` via `index.php` → the web adapter (`core/adapters/web/WebAdapter.php`) picks a renderer by URL suffix: the **html renderer** for place URLs (acts dispatch active), the **raw renderer** for single-container URLs (`.ctn` / `.raw` / `.htmx`-mod; acts dispatch bypassed — see §"Raw-renderer bypass"). `.htaccess` rewrites all non-static paths to `index.php`.
- **Session backend:** PHP native `$_SESSION` cookie store. The framework lib `core/lib/containerist/session.php` is the only writer.
- **Template engine:** `mustache` (Spyc YAML + bobthecow/mustache.php; shared with skin layer and page shell).

## Dispatch — filesystem scan

Acts live at `core/renderers/acts/<stem>.php`. The renderer resolves dispatch at runtime:

```php
$act_path = $acts_dir . $type . '.php';
if (is_file($act_path)) {
  include $act_path;
} elseif (is_file($skin_act)) {
  include $skin_act;          // default fallback
}
```

No registry, no import map. Adding an act = drop a `<stem>.php` file in `acts/`. Removing an act = delete the file. An instance can override any core act by shipping its own with the same stem at the same path. Spec §3 and §4.2 define dispatch *behavior* (byte-match on stem, fall-through to `skin`); filesystem scan and import-map registries are both conformant — TS uses the static import map (`ACTS-in-TS.md` §"Dispatch — static import map").

## Act "signature" — there isn't one

PHP acts are **not functions**. They are top-level PHP statements in a file the renderer `include`s inside its dispatch loop. Variables flow in via PHP's include-scope inheritance:

| Variable | Source | Mutability |
|---|---|---|
| `$block` | The parsed CTN block (`['type', 'fields', 'body']`) the renderer is dispatching | Read-only by convention |
| `$response` | The mutable response state, captured by reference in the renderer's dispatch loop (`core/renderers/dispatch.php`) | **Mutate freely** |
| `$C` | The `Containerist` Core instance, set as `$C = $this->core` immediately before the dispatch loop | Read-only by convention; acts MAY call `$C->ctn()` |

`$block` and `$response` correspond to ACTS-SPEC §4.4's two normative arguments; `$C` is the third impl-specific context permitted by §4.4 (Core handle for acts that need to invoke mods, e.g. `skin` and `error` calling `skinner-block`). An act file restricted to `$block` and `$response` is portable across impls; an act using `$C` is PHP-specific by spec.

## The MUST NOT rules

PHP acts are subject to four restrictions on top of the spec contract. Violations are silent footguns (they produce no parse error but break the dispatch contract).

1. **No `function` declaration at the top level.** A `function foo() {...}` declaration at file scope works the *first* time the act runs but raises `Cannot redeclare foo` on the second invocation in the same request (acts are `include`d, not `require_once`d, since the same act type may legitimately appear in multiple blocks). Helper functions belong in `core/lib/`.

2. **No top-level `return $value`.** A bare `return;` is fine for early exit, but `return $value` is silently discarded by `include` and gives the false impression that the act returns its result. It does not — acts mutate `$response`.

3. **No `echo` / `print` / inline HTML.** Acts append to `$response['body_blocks'][]`; the body is assembled and emitted after all acts run. An `echo` from inside an act leaks bytes to stdout *before* the page-shell wrap, breaking the HTTP response (status+headers haven't been emitted yet, and the leaked bytes appear before `<!DOCTYPE html>`).

4. **No realm globals.** `session.php` and `flash.php` only *queue* mutations onto `$response`; the **adapter** writes the session store at assembly (`session_store_apply_mutations()` in `core/lib/containerist/session.php`). Any act reading `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`, `$_COOKIE` is realm leakage; the adapter has already extracted what acts need into `$response`/`$block`.

Acts MAY set `$response['short_circuit'] = true` to stop dispatch (per ACTS-SPEC §4.5); only `redirect` does so among the seven core acts.

## ResponseState shape

The PHP response state is a plain associative array, initialized by the renderer (`core/renderers/dispatch.php`):

```php
return array(
  'status'            => 200,
  'headers'           => array(),       // map<string, string> — the adapter emits via header()
  'body_blocks'       => array(),       // ordered list of rendered HTML fragments (skin act appends)
  'body_raw'          => null,          // PHP-specific: content-type act's raw payload (skips page-wrap)
  'head'              => array(),       // page-shell slots: title, description, etc.
  'session_mutations' => array(),       // ordered list of [op => set|clear, data|keys => …]
  'flash_writes'      => array(),       // ordered list of [key, msg] entries for next request
  'redirect'          => null,          // {to: string, status: int} when redirect act fires
  'short_circuit'     => false,         // set by redirect; checked by dispatch loop
);
```

`body_raw` is a PHP-specific addition not in ACTS-SPEC §4.4. The `content-type` act uses it to deliver a non-HTML payload (RSS, JSON, plain text) without going through page-wrap. Conformance-neutral: acts that don't set `body_raw` have no behavior change.

`session_mutation` and `flash_writes` shape:

```php
// session_mutations entries:
['op' => 'set',   'data' => ['key1' => $val1, 'key2' => $val2]]
['op' => 'clear', 'keys' => true]                  // full session wipe
['op' => 'clear', 'keys' => ['user_id', 'role']]   // selective clear

// flash_writes entries:
['key' => 'notice', 'msg' => 'Logged in as user 42.']
```

## Dispatch loop

Implementation at `core/renderers/dispatch.php`:

```php
$acts_dir = __DIR__ . '/acts/';
$skin_act = $acts_dir . 'skin.php';
$C = $this->core;                              // exposed via include scope

foreach ($blocks as $block) {
  $type = isset($block['type']) ? $block['type'] : '';
  if ($type === '') continue;                  // §4.2 empty-type → skip

  $act_path = $acts_dir . $type . '.php';
  if (is_file($act_path)) {
    include $act_path;
  } elseif (is_file($skin_act)) {
    include $skin_act;
  }

  if (!empty($response['short_circuit'])) break;
}
```

`$response` is captured by reference at the function boundary (`function dispatch_acts ($blocks, &$response)`); the included act files inherit it from the enclosing scope.

## The seven core acts

All in `core/renderers/acts/*.php`. See ACTS-SPEC §5.1–§5.7 for the normative semantics.

| Act | One-liner | Short-circuit? |
|---|---|---|
| `skin` | Default. Looks up `skin/<type>.{html,css}` via `skinner-block` mod, mustache-renders, appends to `body_blocks`. | No |
| `redirect` | Sets `response.redirect = {to, status:302}`. **Sets `short_circuit = true`.** | **Yes** |
| `error` | Sets `response.status` (clamped 4xx–5xx); skinner-renders the block. Federation errors (with `origin:` field) skip the status-set per FEDERATION-SPEC §9.2. | No |
| `content-type` | Sets `response.headers['Content-Type']`; populates `body_raw` from block body. Page-wrap is skipped on emission. | No |
| `session` | Parses `set:` (mapping) and `clear:` (true / list) → `session_mutations` queue. Writer applied at response-assembly time, before redirect/headers. | No |
| `flash` | Validates `key`/`msg`, appends `FlashEntry` to `flash_writes`. Read on the next request as a position-4 fact under `key`, then drained. | No |
| `title` | Writes `response.head['title']` (last-write-wins by emission order). The page-shell skin reads `{{head.title}}`. | No |

`skinner-block` is a framework mod (`core/mods/skinner-block.html.php`); the `skin` and `error` acts both call `$C->ctn('skinner-block', $block)` to render. This is why those two acts use `$C`; the other five do not.

**Note on the `error` act (5.1+).** The `CTN: error` block may originate from a mod (intentional control flow) or from the Core itself when an invocation fails — per ACTS-SPEC §5.3 source-opaque dispatch and the Core's `$C->ctn()` error contract. The act handles both byte-identically; the block's origin is opaque at the act layer.

## Session backend

Server-side `$_SESSION` cookie store. PHP's native session implementation, started lazily by `core/lib/containerist/session.php`.

**Cookie:** name from `session_name()` (default `PHPSESSID`); HttpOnly + SameSite=Lax via `session_set_cookie_params()`. Secure flag respected when the request is HTTPS. Path `/`.

**Session storage:** server-side filesystem (default `session.save_handler = files`). For multi-host deployment, instance configures `session.save_handler` and related ini directives in `config.php` or `config.local.php`; the framework does not impose a backend.

**Flash slot:** stored under `$_SESSION['_flash']` as a flat map. The **adapter** resolves facts (`session_store_resolve_facts()`, drain-on-read) at request start; the **html renderer's** emit (`act_emit_response`) calls `session_store_apply_flash_writes()` at response assembly to populate the slot for the next request.

**Mutations applied at response assembly** by the html renderer's `act_emit_response`, in this order (matching ACTS-SPEC §4.4 emission steps 1–4). (The adapter applies the PRG stash mutations separately, during routing.)

1. `session_store_apply_mutations($response['session_mutations'])` — set/clear in queued order.
2. `session_store_apply_flash_writes($response['flash_writes'])` — append entries.
3. `session_write_close()` happens implicitly on script end.

A `clear` mutation followed by a `flash` write is the logout pattern: the clear runs first (wipes the session), then the flash write populates `_flash` for the post-redirect request to display.

## Request lifecycle

End-to-end on the no-suffix (full HTML page) path:

1. Apache/`.htaccess` rewrites the URL → `index.php?q=<path>`.
2. `index.php` defines `ROOT_DIR`, `CORE_DIR`; loads `containerist.php`; instantiates the Core, then the web adapter. **The adapter picks a renderer by URL suffix:** `.ctn` / `.raw` / `.htmx`-mod → raw renderer; `.place` → source renderer; everything else (no suffix, `.html`, `.htmx`-place fall-through, `.placectn`, `.trace`) → html renderer. Headless instances (no `places/` directory) resolve only the raw-renderer paths.
3. `WebAdapter::handle()` parses the URL via `Request`, classifies suffix (no-suffix / `.html` / `.place` / `.placectn` / `.trace` / alias-default / unknown), and routes the place-pipeline path.
4. For the place-routed path: call `arg_lifecycle_resolve_request_facts($_GET, $_POST)` (4.4.6+; see §"Arg-lifecycle helpers" below) to get the four position-4–6 dictionaries (session facts, header facts, filtered query, POST). Pass all six lifecycle positions to `place-ctn` mod.
5. `place-ctn` resolves the stack file, composes per-line invocation args via `arg_lifecycle_merge($resolved_with_per_line, $facts)` in canonical lifecycle precedence order (ACTS-SPEC §6), invokes each container, concatenates emitted CTN.
6. The renderer parses the combined CTN into blocks, dispatches each through acts (this document's §"Dispatch loop").
7. Response assembly — the **html renderer** emits (the buffered dispatcher calls `act_emit_response` in `core/renderers/dispatch.php`; the streaming dispatcher flushes incrementally). The html renderer performs its own wire I/O because streaming can't defer a finished response to the adapter:
   - Apply `session_mutations` and `flash_writes` (above).
   - If `redirect` set → emit Location + status, return.
   - Else: apply `status` and `headers`. If `body_raw` set → emit raw, return. Otherwise → page-wrap (full page) or stylesheet-prepend (`.htmx` fragment) and emit.

   (The `raw` / `source` / container paths differ: their renderer returns the response and the **adapter** emits — `WebAdapter::emit_raw` / `emit_place_ctn` / `emit_place_source`.)

## Raw-renderer bypass

URL-suffix dispatch paths (`.ctn`, `.raw`, `.htmx`-mod) bypass the acts dispatch entirely. The web adapter routes them to the **raw renderer** (`core/renderers/raw/`): it resolves args via `arg_lifecycle_resolve_request_facts()` + `arg_lifecycle_merge()`, calls `$C->ctn($id, $args)`, and the body is the returned CTN (`.ctn` / `.raw`: `text/plain` verbatim; `.htmx`: skinner-rendered fragment). No act runs, no session mutation occurs.

`$C->ctn()`'s always-CTN return contract (always a string, `CTN: error` block on failure) means the raw path has no try/catch and no error-translation glue. A schema 400 or throw 500 from the Core lands as a `CTN: error` block on stdout, with HTTP status set from the block's `code:` field. The `.htmx` fragment path renders the error through the `error` act's skin.

`.place` / `.placectn` / `.trace` suffixes route to the place pipeline (html / source renderers), not the raw renderer.

## Arg-lifecycle helpers

ACTS-SPEC §6 defines a six-position arg lifecycle. Positions 1–3 are place-side (URL parts → place `@args` → per-line args); positions 4–6 are realm-side (session facts → header facts → filtered query → POST). Since 4.4.6, the realm-side assembly lives in `core/lib/containerist/arg_lifecycle.php` as two pure helper functions, used by every dispatch path and by `place-ctn` itself:

| Helper | Returns | Used by |
|---|---|---|
| `arg_lifecycle_resolve_request_facts($get_array, $post_array)` | structured dict `{session_facts, auth_header_facts, query, post}` | the web adapter (`handle()` + arg-build), the placectn path |
| `arg_lifecycle_merge($base_args, $facts)` | flat dict (base args with positions 4–6 layered on in lifecycle order) | `core/mods/place-ctn.ctn.php` (per-line composition), the web adapter (suffix dispatch flattening) |

`resolve_request_facts` reads `$_SESSION` via `session_store_resolve_facts()` and `Authorization` headers via `auth_header_resolve_facts()`; both are realm-aware and live in their own libs. The helper centralizes the `q`/`trace` filter on query (ACTS-SPEC §6 reserved names in the PHP/Apache realm) and the read-POST default.

`merge` is realm-agnostic — it's pure dict iteration in canonical lifecycle precedence order (`session_facts → auth_header_facts → query → post`). The order is the spec; the helper is the impl.

Prior to 4.4.6 these assembly blocks were duplicated four times (three dispatch-path methods + the merge block in `place-ctn.ctn.php`). The 4.4.2 patch closed a realization gap that the duplication had enabled; 4.4.6 collapses the duplication so the next gap can't open the same way.

## CLI adapter

`core/adapters/cli/CliAdapter.php`. Invoked by the `ctnr` shell wrapper. Uses the raw renderer (CTN → stdout); no acts dispatch — parses argv (positional, `key=value`, single-JSON forms), calls `$C->ctn($mod, $args)`, prints the returned CTN verbatim to stdout. Per ACTS-SPEC §7.2:

| Block | CLI behavior |
|---|---|
| `skin` | Bypassed; raw CTN to stdout |
| `redirect` | Logged to stderr, passed through |
| `error` | Status set; exit code derived from leading block (see below) |
| `content-type` / `session` / `flash` / `title` | Logged to stderr, ignored |

**Exit-code contract (5.1+; ACTS-SPEC §7.2 normative at 1.2).** Process exit code `1` when the returned CTN stream begins with `CTN: error` (whether emitted by a mod or by the Core); `0` otherwise. The error block itself lands on stdout — errors are typed output (Pillar 11) and downstream consumers `parse_ctn` / grep / pipe them like any other CTN. The exit code gives shell `&&` / `if` chains the binary success signal.

Argv parse errors, usage messages, and pre-Core failures (mod-not-found before any invocation) emit to stderr with exit `1`. Once `$C->ctn()` has been called, the returned CTN is the typed output stream — including error blocks — and is written to stdout. PHP reference: `return (strncmp(ltrim($result), 'CTN: error', 10) === 0) ? 1 : 0;` after the stdout `echo`.

The cli adapter exists for inspection and scripting; it is never the primary delivery surface, so the full acts layer would be overkill.

## File locations

- `core/renderers/acts/*.php` — the seven core acts (`skin`, `redirect`, `error`, `content-type`, `session`, `flash`, `title`).
- `core/renderers/dispatch.php` — the act-dispatch loop (parse blocks; resolve + run acts in order).
- `core/renderers/html/` — HTML body assembly (page-wrap; buffered/streaming). `core/renderers/raw/` — raw CTN passthrough (`.ctn` / `.raw` / `.placectn`; skips act-dispatch). `core/renderers/source/` — `.place` source dump.
- `core/adapters/web/WebAdapter.php` — `handle()`: parse request, pick renderer by suffix, call Core, perform the response. (Absorbs the 5.1 PlaceStacker + CtnStacker.)
- `core/adapters/cli/CliAdapter.php` — cli adapter: raw renderer → stdout + exit-code.
- `core/adapters/web/Request.php` — URL parse, suffix classification, numeric-parts extraction.
- `core/adapters/web/Trace.php` — `?trace=1` debug dump (gated by `TRACE_ENABLED`).
- `core/lib/containerist/arg_lifecycle.php` — lifecycle position-4–6 helpers (`arg_lifecycle_resolve_request_facts`, `arg_lifecycle_merge`); see §"Arg-lifecycle helpers" above. **4.4.6+.**
- `core/lib/containerist/session.php` — session-store helpers (resolve, apply, mutate).
- `core/lib/containerist/auth_header.php` — header-resolved-facts (`bearer`).
- `core/mods/place-ctn.ctn.php` — composes the CTN stream acts dispatch against.
- `core/mods/skinner-block.html.php` — used by `skin` and `error` acts.

## Idioms and non-requirements

- Acts are top-level PHP, **never** classes or functions. State lives on `$response`, nowhere else.
- No plugin system, hooks, or middleware. The dispatch loop is a `foreach` (ACTS-SPEC §"Design goals").
- No runtime registry or import map (filesystem scan is the registry).
- No act composition (per ACTS-SPEC §9).
- No priority/order config — order is emission order (ACTS-SPEC §4.3).
- Helper logic that two acts share lives in `core/lib/`, not in a base act class.
- An act that needs to render content invokes `$C->ctn('skinner-block', $block)`; it does not Mustache-render directly.

## Conformance

Conformant with ACTS-SPEC 1.3 (draft) including the §4.4 third-argument clarification (PHP passes `$C` via include scope as the impl-specific context). All seven core acts implemented. End-to-end flow verified by `core/tools/smoke.sh` (CLI) and `core/tools/smoke-http.sh` (HTTP, including session/flash round-trip).

§6.1 (header-resolved facts) implemented at framework 4.4.1 — `bearer` fact populated from `Authorization: Bearer <token>` headers, sibling resolver to session-resolved facts at position 4.

§6 lifecycle realization complete at framework 4.4.2 — all six positions of the precedence list realized on every render path (place-routed and URL-suffix dispatch paths produce identical position-4–6 behavior). Centralized into one canonical assembly path at framework 4.4.6 via the `arg_lifecycle.php` lib (§"Arg-lifecycle helpers" above).

## Related

- `CTN-in-PHP.md` — CTN parser producing the blocks acts dispatch against.
- `IN-in-PHP.md` — `@in` schema enforcement. As of 5.1, enforcement runs at the Core boundary inside `$C->ctn()`; missing-required surfaces as a `CTN: error code 400` block in the returned stream. Pre-5.1 the impl caught `InvalidArgumentException` at the dispatch boundary; the spec rule (IN-SPEC 1.1+) is that the Core owns enforcement.
- `PLACE-in-PHP.md` — composition layer + arg-lifecycle positions 1–3 and 5–6 (5.0+). *(Supersedes the archived `archive/4.x-stack-format/STACK-in-PHP.md`.)*
- `FEDERATION-in-PHP.md` — `error` act's federation-error special case (§9.2).
