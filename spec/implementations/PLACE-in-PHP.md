# PLACE-in-PHP — PHP Implementation Guide for Places

**Status:** Informative. `PLACE-SPEC.md` is authority; on conflict the spec wins.

This sidecar documents the PHP-specific conventions for the place format introduced at Containerist 5.0. It supersedes the archived `archive/4.x-stack-format/STACK-in-PHP.md` — 5.0 instances no longer ship the `.txt` stack format the older sidecar described. The reference impl is `containerist-php` 5.0.0+.

**Dated claims.** Specific version, library, or runtime claims below are dated so a future reader can verify they're still current.

---

## Target environment

- **PHP** 7.4+ (8.x tested through 8.4.12 as of 2026-05-21). No autoloader, no Composer.
- **YAML library:** Spyc (vendored at `core/lib/yaml/yaml.php`, ~0.5 era; YAML 1.1 surface). Spyc has the YAML 1.1 boolean coercion behavior — see §"YAML 1.1 coercion" below; that footgun has bitten three directives so far.
- **HTTP layer:** Apache or `php -S` via `index.php` → the web adapter (`core/adapters/web/WebAdapter.php`) picks a renderer by URL suffix: the html renderer for place URLs, the raw renderer for single-container URLs (`.ctn`, `.raw`, `.htmx`-mod). `.htaccess` rewrites all non-static paths to `index.php`. Headless instances (no `places/` directory) resolve only the raw-renderer paths; any other URL is a 404.
- **Session backend:** PHP native `$_SESSION` cookie store; written only by `core/lib/containerist/session.php` and the PRG stash at `core/lib/prg/prg.php`.
- **Template engine:** mustache via `core/lib/mustache/mustache.php`; shared with the skin layer and page-shell template.

## File layout

A place file is one CTN document. The reference reads it from `PLACES_DIR` (default `ROOT_DIR/places/`), keyed by filename stem against URL parts using the wildcard-priority resolver in `core/adapters/web/Request.php::find_place_id`. Only files with `.place` extension are matched; 4.x's `.txt` extension is no longer recognized.

```php
$places_dir = defined('PLACES_DIR') ? rtrim(PLACES_DIR, '/') : (__DIR__ . '/../../../places');
foreach ($candidates as $c) {
  $candidate = compute_pattern($c, $parts);
  if (is_file($places_dir . '/' . $candidate . '.place')) return $candidate;
}
return null;
```

`PLACES_DIR` is defined in `core/config.defaults.php` and SHOULD be overridable from the instance's `config.php` via the standard `if (!defined)` guard.

## The frontmatter-only canonical form

The most important PHP impl rule for places: **the canonical form has no `---` separator and no body section.** Every place-level key — `@args`, `@federation`, `PLACE`, `purpose`, `dispatcher`, `auth`, `prg`, `containers`, `regions` — lives in the CTN frontmatter. The CTN parser's first-block `fields` mapping carries the entire declaration.

```
CTN: place
@args: id = {{2}}
containers:
  - page-header
  - "article?id={{id}}"
  - page-footer
```

There is no `---` line. `$blocks[0]['fields']` is the parsed YAML mapping; `$blocks[0]['body']` is the empty string. A migration tool that emits `CTN: place\n---\ncontainers:\n  - foo` looks plausible to a reader but parses to `fields = []` and a body string containing "containers:\n  - foo" — and the body string is ignored by place-ctn. The migration tool `core/tools/stack-to-place.php` learned this the hard way during step 2 of the 5.0 propagation; its output is now frontmatter-only.

If you write a place file by hand with a `---` line you don't intend, the parser silently accepts it and the place dispatches to empty. There's no spec-level reject for an unexpected body; instances SHOULD treat this as authoring drift and lint for it. The `core/tools/lint.php` `check_place()` function is the right home for that lint rule.

## YAML 1.1 coercion — the recurring footgun

Spyc honors YAML 1.1 implicit type rules: unquoted `off`, `on`, `yes`, `no`, `false`, `true` parse as booleans. PLACE-SPEC values that use these tokens (`prg: off` is the obvious one; `@federation`'s policy values include `off`) need normalization at the read site or quoted at the author site.

The reference uses normalize-at-read consistently. Three current sites:

| Directive | Read site | Normalization |
|---|---|---|
| `@federation` | `core/mods/place-ctn.ctn.php` (in the `@federation` parse loop) | `if ($policy_val === false) $policy_val = 'off';` |
| `dispatcher` | `core/adapters/web/WebAdapter.php::handle()` | preg_match against `/^[a-z][a-z0-9_-]*$/` rejects bool; place fails to load with explicit error |
| `prg` | `core/adapters/web/WebAdapter.php::handle()` | `if ($raw === false) $raw = 'off'; if ($raw === true) $raw = 'auto';` |

Any new PLACE-SPEC directive whose values include YAML-1.1-coerced tokens MUST add a similar normalization. The pattern is mechanical; the cost of forgetting is a 500-with-empty-message and a confused implementer (see step 6 of the 5.0 propagation journal).

If your YAML parser is YAML 1.2-only (Go's `yaml.v3`, Rust's `serde_yaml`), the coercion doesn't happen and direct string equality works. Note the divergence; document the per-port choice in the per-language sidecar.

## Frontmatter directives — read sites

**`@args` and `@federation`** are CTN frontmatter directives starting with `@`. YAML 1.2 reserves `@`; Spyc accepts it silently. For strict-YAML parsers, the framework provides a preprocessing step that quotes `@<word>:` at line-start before parsing:

- `core/lib/containerist/federation.php::federation_preprocess_at_federation_text($ctn_text)` — preprocesses the `@federation` block specifically.
- Stack/place-side preprocessing for `@args` lives in callers; the reference doesn't need it because Spyc tolerates `@`.

A strict-YAML port (TS, Go, Rust) MUST preprocess. The reference's tolerance is a Spyc-only affordance.

**`dispatcher`** selects which dispatcher composes the place. The reference reads it in `WebAdapter::handle()` after parsing the place block:

```php
$dispatcher_name = isset($place_data['fields']['dispatcher'])
  ? (string)$place_data['fields']['dispatcher']
  : 'buffered';
```

Names map directly to filesystem paths: `core/renderers/html/<name>/dispatch.php` must exist and define a function named `<name>_dispatch($place_data, $context, $core)`. Adding a new dispatcher = drop a directory at that path with a dispatch.php inside. No registry.

**`prg`** controls per-place opt-out from auto-PRG (PLACE-SPEC §6.5; added at 1.1). Values: `auto` (default) or `off`. Read site: `WebAdapter::handle()` before the PRG decision. Normalization for the YAML 1.1 `off`/`on` coercion is mandatory (see §"YAML 1.1 coercion" above).

**`auth`** is read by `index.php` at the route-resolution boundary, not by the place renderer. The reference uses `file_declares_auth_required($match['path'])` from the instance's `lib/auth.php` (instance-specific; the framework provides the read mechanism, not the redirect policy). The mechanism is: read the place file, check the YAML field, redirect to the configured login URL if `auth: required` and the request is unauthenticated.

## Container-reference parsing

`core/lib/containerist/place_ref_parser.php` exposes two pure functions:

- `place_parse_ref_line($line)` → `['prefix' => string, 'name' => string, 'args' => string]` or `null`. Splits a container reference (`name?k=v&k2=v2`, `htmx: name`, `http://…`) into its three components per PLACE-SPEC §8.2.
- `place_parse_per_line_args($args_str, $parts_args, $resolved)` → `['k' => 'v', …]`. Parses the per-line-arg suffix, resolving `{{N}}` against URL parts and `{{name}}` against earlier @args bindings.

The grammar was lifted from STACK-SPEC §7 with the body-line context generalized to "any sequence value under `containers:` or `candidates:`."

Pure functions; no I/O; no side effects. Kept in `core/lib/` (not inlined into `place-ctn.ctn.php`) because PHP `include` re-declares top-level functions on each mod invocation — and that fatals on the second invocation in a single request.

## Composition — place-ctn

`core/mods/place-ctn.ctn.php` is the framework primitive that assembles a place's combined CTN. It:

1. Fetches the raw place-file content via `$C->ctn('place-source', ['id' => $id])`.
2. Preprocesses `@federation:` for Spyc.
3. Parses the CTN; takes the first block; verifies `type === 'place'`.
4. Builds a `$pending` list of (line, region_args) tuples — flat for the simple-form, region-aware for the explicit-form. Region wrappers emit `CTN: region-open` and `CTN: region-close` marker blocks bracketing each region's container output (see §"Region pseudo-acts" below).
5. Resolves the `@federation` policy map and the `@args` bindings.
6. Iterates `$pending`; for each container ref, composes invocation args (resolved @args + region attrs + per-line args + position 4-6 facts via `arg_lifecycle_merge`), invokes the mod or container, concatenates the CTN output. Federation refs (direct `http(s)://` and deferred `htmx: http(s)://`) detour through `federation_resolve()` or emit `CTN: htmx-placeholder`.

The implicit-`standard`-wrap (`CTN: standard\n---\n<output>`) is applied at the composition boundary for any mod output that doesn't already begin with `CTN:`.

## The two dispatchers

`core/renderers/html/` ships two implementations:

- **`buffered/dispatch.php`** — the default. Composes via `place-ctn`, parses the result, dispatches blocks through acts, and assembles the response; the adapter emits it. Page shell, head-slot writes (`CTN: title`), and `CTN: redirect` short-circuit all work correctly because the response is fully composed before any byte goes to the wire.

- **`streaming/dispatch.php`** — the opt-in. Emits the page-shell head, then iterates regions directly (not via `place-ctn`'s `ob_start`-captured composition), flushing each container's `echo` output to the wire as the container produces it. Containers that emit incrementally (`flush_chunk()`-style; LLM token streams) stream to the client in real time. Acts that depend on the *complete* CTN stream (`title`, `redirect` short-circuit) are constrained — see PLACE-SPEC §9.2.

The dispatcher contract is the function signature `<name>_dispatch($place_data, $context, $core)` and the responsibility for emitting the full HTTP response. Anything else is at the dispatcher's discretion. Both shipped dispatchers define a `streaming_trigger($name, $payload)` function at file scope so a mod can call it unconditionally; the buffered version is a no-op, the streaming version emits an inline `<script>htmx.trigger(...)</script>` block. A mod MUST NOT branch on which dispatcher invoked it (pillar 6 + 8 drift signal).

## Region pseudo-acts

`place-ctn` emits `CTN: region-open` and `CTN: region-close` marker blocks that bracket each region's container output when the place uses the explicit-form (`regions:`). These are framework-internal block types, not part of the public CTN type vocabulary. The dispatchers wrap each region's rendered output in a `<div class="ctn region region-<name>" data-region="<name>">` element.

The buffered dispatcher post-processes the assembled body to strip empty region wrappers (where the only content is HTML comments) — `panels-row`'s invisible-marker region needs to disappear in the simple-form pages.

Sites that need region-wrapper customization (different element type, additional attributes) can override `core/renderers/acts/region-open.php` and `core/renderers/acts/region-close.php` per the standard act-override mechanism.

## Auto-PRG and the bypasses

`core/lib/prg/prg.php` implements the POST-redirect-GET flow described in ACTS-SPEC §7.1. `WebAdapter::handle()` decides whether to fire:

```php
if ($is_post && $prg_enabled && !$is_htmx && !$has_bearer) {
  $out = prg_stash_post($server, $post, $place_name, $_SESSION);
  session_store_apply_mutations($out['session_mutations']);
  header('Location: ' . $out['redirect'], true, 303);
  return;
}
```

Three bypasses:

1. **`!$is_htmx`** — `prg_is_htmx_request($server)` checks for `HX-Request: true`.
2. **`!$has_bearer`** — `WebAdapter::request_has_bearer($server)` checks for `Authorization: Bearer …` (case-insensitive; honors both `HTTP_AUTHORIZATION` and Apache-stripped `REDIRECT_HTTP_AUTHORIZATION`).
3. **`$prg_enabled`** — the place's `prg:` frontmatter value resolves to `auto` (true) or `off` (false).

Stash key namespace: `$_SESSION['_prg_pending'][$place_name]`. Consumed on the subsequent GET via `prg_consume_pending()`. The stash survives one redirect; if the user follows the 303 in a different browser tab, the original POST is consumed in whichever tab gets there first.

Smoke writers — drive PRG round trips as two curl invocations sharing a cookie jar. `curl -L -X POST` preserves the method across redirects (the `-X POST` flag overrides curl's default 303→GET conversion) and will re-POST on every hop. Use POST then GET, not POST with `-L`.

## Migration from `.txt` stacks

`core/tools/stack-to-place.php` converts 4.x `.txt` stacks to 5.0 `.place` files. One-shot per instance: run it, verify output, retire the `stacks/` directory.

```sh
php core/tools/stack-to-place.php stacks places
```

Output is frontmatter-only by construction. The tool emits `CTN: place` as the header; lifts `@args` and `@federation` frontmatter verbatim; converts body lines (the 4.x container-ref list) into a YAML `containers:` sequence inside the CTN frontmatter; quotes refs containing `{`, `}`, `?`, `:`, `&`, `'`, `"`, `|`, `>`, `*`, or `!`. Comments (lines starting with `#`) are preserved as YAML comments interleaved with the sequence entries.

The tool does NOT touch source `.txt` files. Operator removes `stacks/` after verifying `places/` output.

## File locations

| Path | Role |
|---|---|
| `core/config.defaults.php` | `PLACES_DIR` constant |
| `core/adapters/web/Request.php` | URL → place-id resolution |
| `core/adapters/web/WebAdapter.php` | Web adapter: parse request, PRG decision, pick renderer by suffix, call Core, perform response. |
| `core/renderers/dispatch.php` | Act-dispatch loop (resolve + run acts in block order) |
| `core/renderers/raw/` | Raw CTN passthrough (`.ctn`, `.raw`, `.placectn`); skips act-dispatch |
| `core/renderers/html/buffered/dispatch.php` | Default dispatcher |
| `core/renderers/html/streaming/dispatch.php` | Opt-in streaming dispatcher |
| `core/renderers/acts/region-open.php`, `region-close.php` | Region-wrapper acts (overridable) |
| `core/mods/place-ctn.ctn.php` | Place composition |
| `core/mods/place-source.ctn.php` | Raw place-file read |
| `core/lib/containerist/place_ref_parser.php` | Container-ref parser |
| `core/lib/containerist/place_loader.php` | Helper: parse the first block of a place file |
| `core/lib/containerist/route_resolver.php` | URL → (pattern, extension, path, parts) lookup; used by alternate-entry-point adapters |
| `core/lib/containerist/arg_lifecycle.php` | Realm-side arg-lifecycle helpers |
| `core/lib/prg/prg.php` | POST-redirect-GET primitives |
| `core/tools/stack-to-place.php` | 4.x → 5.0 migration converter |
| `core/tools/lint.php::check_place()` | Place-file lint (validates `CTN: place` header; rejects 4.x-shape branching/templating constructs) |

## Conformance status (as of 2026-05-21)

- **containerist-php 5.0.0** — full PLACE-SPEC 1.1 conformance. Both dispatchers ship. All six core acts run. Auto-PRG with both bypasses + per-place override. Smokes 21 CLI + 36 HTTP, both 0 fail.
- **konnexus-ai** — synced byte-for-byte with reference. Streaming dispatcher exercised in production by AI surfaces. Instance smoke green.
- **konnexus.net** — synced byte-for-byte with reference. Buffered dispatcher only (no AI surfaces). 86/0 across three smokes.
- **pond-mk4** — synced byte-for-byte with reference. Buffered dispatcher only. Three API places declare `prg: off`. Instance smoke 22/0.

## Idioms and non-requirements

- **Place files MAY have a `---` separator and body.** A non-empty body is ignored by `place-ctn`; the parser tolerates it. The canonical form is frontmatter-only and tools (lint, migrator) target that form, but the wire format doesn't reject the older shape.
- **The reference does NOT accept `.stack` or `.stackctn` as compatibility aliases.** PLACE-SPEC §4.1.5 allows implementations to accept the 4.x suffix names; the reference deliberately does not (clean cut at 5.0). Instances migrate before they upgrade.

## Related

- `PLACE-SPEC.md` — wire format authority for places.
- `ACTS-SPEC.md` §7.1 — auto-PRG flow and the conformance clause naming it as web-adapter policy.
- `ACTS-in-PHP.md` — PHP-specific act-file conventions.
- `IN-in-PHP.md` — PHP-specific `@in` handling.
- `FEDERATION-in-PHP.md` — federation lib and `place-ctn` integration points.
- `archive/4.x-stack-format/STACK-in-PHP.md` — the deprecated 4.x sidecar this document supersedes.
- `RELEASE-5.0.md` — operator-facing release notes for 5.0.
- `logs/2026-05-21-5-0-places.md` — the full six-step propagation journal, including the YAML-1.1 footgun's three discovery sites.

---

*This document evolves with PHP implementation state. Date any claim; update or remove when stale. PLACE-SPEC.md is the target; this file explains how to hit it in PHP.*
