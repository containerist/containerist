# Containerist — Changelog

Project-wide version history. The reference implementation (`containerist-php`) carries the canonical version number; sibling implementations (`containerist-ts`, `containerist-go`) catch up entry-by-entry. Every entry below is **strictly additive** unless flagged otherwise — sites that don't emit the new constructs see no behavior change.

For why a change exists, read the matching entry in `logs/`. For what the system *is*, read `docs/containerist.md` (operator briefing). For ambition not yet promoted, read `direction.md`.

---

## 2026-06-10 — WIRE-SPEC 0.1 draft (new optional adapter spec; no framework version change)

`WIRE-SPEC.md` lands: the **wire adapter**, CTN-over-HTTP — the third realm adapter beside web and cli, serving the CTN waist itself (`text/ctn`) to out-of-process renderers. Surface: `GET /places{path}.spec` (place structure as one `CTN: place` block — no composition), `GET|POST /{name}.ctn` (container content / P2 mutations relayed verbatim, no server-side act dispatch), `.events` reserved at 501. Opt-in PRG for no-JS forms via a reserved `_return` field (open-redirect-guarded); arg lifecycle realizes ACTS-SPEC §6 positions 4–6 on every request; errors stay in-band as `CTN: error` blocks at HTTP 200, with exactly one narrow promotion (the Core's container-not-found → 404).

Spec-only and strictly additive: extracted from the already-shipped Go implementation (`containerist-go/adapters/spa`, proven by the rook-flasher instance's two out-of-repo consumers), not designed fresh. CTN/PLACE/IN/ACTS specs unchanged. The PHP reference is untouched — the spec is **optional** (MAY provide; MUST conform if provided); PHP/TS conformance rows are "no". Deliberately deferred, recorded in WIRE-SPEC §12: `columns:` place-grammar promotion (a PLACE-SPEC question), named reusable regions ("stacks" — n=1 consumer + retired-term collision), the `.events` SSE contract, server-side session mutation on the wire, and the Go package's `spa` → `wire` naming alignment. See `logs/2026-06-10-wire-spec.md`.

## 5.2.2 — `audit-always-ctn` lint tool + 5.1 post-migration checklist

Closes the call-site-audit gap surfaced by the reader.konnexus.net migration (logs/2026-05-28-reader-4-to-5-2.md, logs/2026-05-28-call-site-bugs-framework-implications.md). 5.1 made `$C->ctn()` always-CTN (never throws, never returns false, always a CTN string with `CTN: error` blocks on failure) — but the existing `core/tools/migrate-mod-to-ctn.php` codemod only rewrites the *call* (`$C->mod(` → `$C->ctn(`). Pre-5.1 guards around the return value (`=== false`, `=== ''`, `try/catch`, `is_string($x) && $x !== ''`) silently took the wrong branch under the new contract — error blocks passed the "is a non-empty string" check, hard-fail branches never fired, dead `catch` clauses looked like working error handling.

Ships:

- **`core/tools/audit-always-ctn.php`** — reports (does NOT auto-fix) call-site guard shapes that break under always-CTN. Walks `modules/`, `mods/`, `test/`, `lib/` by default; skips `core/`. Each hit gets a classification:
  - `[A]` hard-fail intent (`=== false`, `!is_string(\$x) || \$x === ''`) → rewrite to `strpos(\$x, "CTN: error\n") === 0`.
  - `[B]` optional use intent (`is_string(\$x) && \$x !== ''`) → rewrite to `strpos(\$x, "CTN: error\n") !== 0`.
  - `[TRY]` try/catch wrapping `\$C->ctn()` → catch is dead; replace with strpos gate or drop.

  Narrowed to "the same `\$var` was assigned a `\$C->ctn()` result within 20 lines above" to avoid false-positives on `file_get_contents` / curl / URL-validity checks. Reviews still need eyes — the tool reports a candidate; the rewrite needs human classification.

- **`RELEASE-5.1.md`** post-migration checklist (steps 4–6): always-CTN audit, smoke discriminator pins, mod-load smoke (`./ctnr` for every mod to surface "Call to undefined" / Fatal). Replaces the previous one-line "run your suite and HTTP smoke" with concrete commands.

- **`containerist-evolve` skill** Step 4 now mandates the three audits for instance migrations crossing the 5.0 → 5.1 boundary. The single-quoted-grep footgun (false-zero from bash interpreting `\$` as `$`) is documented in line.

Cross-tier sweep with the new tool:
- containerist-php (5.2.1): 0 hits.
- konnexus-ai: 3 hits in `modules/nexus/` (try/catch + empty-check around `$core->ctn()`). Fixed in the same release.
- reader.konnexus.net: 0 hits (1 site missed in the May 28 reader migration, caught and fixed by the new audit tool on its first run — `modules/dailyreader/digest-sources.ctn.php`).
- pond-mk4: 10+ hits across 5+ files. NOT fixed in this release — flagged as a follow-up patch.

Strictly impl-only and additive: the spec is unchanged (CTN-SPEC §4.4 has documented the always-CTN error model since 5.1); no existing code is forced to change; the audit is opt-in via the new tool. See `logs/2026-05-28-call-site-bugs-framework-implications.md` for the structural read.

---

## 5.2.1 — `page-display` always-CTN gate

Closes a 5.1 call-site gap in the reference's `modules/pages/page-display.ctn.php`: the not-found fallback was gated on `if ($content !== false)`, an idiom from before 5.1 made `$C->ctn()` always-CTN. Under 5.1+ that branch is permanently true — the fallback never runs and users see the raw Core message (`container 'pages/no-such-page' not found`) instead of the `not-found` mod's intended `page not found`. Gate switched to `strpos($content, "CTN: error\n") !== 0` (PHP 7.4+; equivalent to the TS port's `content.startsWith('CTN: error')`).

Surfaced by the TS port catching the same bug with a failing test (`containerist-ts/test/pages.test.ts`). The PHP smoke matched `CTN: error` in either branch (broken or fixed) and didn't discriminate — 5.2.1 adds a smoke pin on the `page not found` message so the regression can't return silently.

Strictly impl-only: CTN-SPEC §4.4 already documents the always-CTN error model; the spec is unchanged. Header comments in the affected file also swept for 4.x stack-file refs. See `logs/2026-05-28-page-display-back-promotion.md`.

---

## 5.2.0 — CTN-waist hourglass *(API-restructure; wire + acts-contract unchanged)*

Re-architects the realm/render layer around **CTN as the architectural waist**. The wire format and the ACTS §4 dispatch contract are unchanged; this is a behavior-preserving extraction + rename of structure that already lived inside the 5.1 Stacker. Every URL, suffix, and rendered byte is identical — the regression gate is the existing smokes staying green.

What changed:

- **Stacker / Placer / Lifter retired → Core / renderer / adapter.** The 5.1 `PlaceStacker` / `CtnStacker` / `CliStacker` classes dissolve into three layers: **Core** (`core/containerist.php` — `id + args → CTN`, composes containers *and* places, realm-free + format-free), realm-free **renderers** (`core/renderers/` — CTN → realm-neutral response; html / raw / source; runs the acts), and thin per-realm **adapters** (`core/adapters/web/` = `WebAdapter`; `core/adapters/cli/` = `CliAdapter`). Flow: `request → adapter → core → renderer → adapter → response`. The adapter conducts (picks the renderer by URL suffix, calls Core, routes CTN through the renderer, performs the response); Core and renderer never call each other.
- **`core/stackers/` → `core/adapters/`; new `core/renderers/`** gathering the old `act_dispatch.php` + `acts/` + `dispatchers/` (→ `renderers/html/`), organized by format.
- **Acts re-homed to the renderer, and named realm-neutral.** Acts *describe* effects into a realm-neutral response (set a field, queue a mutation); the adapter *performs* the realm I/O. Corrects the prior "acts are realm code" framing. The seven core acts and the §4 dispatch contract are unchanged.
- **Vestigial `stack_` → `place_` cleanup.** `find_stack_id` → `find_place_id`, `$request->stack_id` → `$request->place_id`, `stack_parse_ref_line` / `stack_parse_per_line_args` → `place_parse_*`. (Lowercase "stack" as a fixed region's container list is retained — only the mislabeled place-meaning symbols change.)

Spec impact:

- **ACTS-SPEC 1.2 (draft) → 1.3 (draft).** Acts re-homed under "the renderer"; realm I/O attributed to "the adapter"; realm-notes reframed (Web/CLI Stacker → web/cli adapter); the "acts are realm code" framing corrected to realm-neutral. §4 contract and §5 core acts unchanged — a 1.2-conformant implementation is automatically 1.3-conformant.
- **`containerist.md` 5.1.0 → 5.2.0 / doc 3.2.** Vocabulary, file-layout, Pillar 8, entry-point pattern, acts, suffix → renderer map, deployment shapes, arg-lifecycle reframed.
- **PLACE-SPEC / IN-SPEC / FEDERATION-SPEC** — terminology relabel only (no grammar change, no version bump). **CTN-SPEC** — unchanged.

What's unchanged: the wire format, the acts dispatch contract (§4) and the seven core acts (§5), mods, places, skins, federation, the CLI exit-code contract, full-vs-headless deployment, the eight pillars (Pillar 8 reworded: the realm adapters are the only realm-aware layer; Core and renderers are realm-free).

Deferred: the `.text` renderer (terminal-readable output) is reserved in the suffix map but not shipped in 5.2.

Migration: see `RELEASE-5.2.md` (sync core, re-point entry points). Trigger context and the extraction arc: see `logs/2026-05-27-5-2-ctn-waist-hourglass.md`.

---

## 5.1.0 — Core simplification + headless deployment *(API-breaking; wire-format additive)*

Lands **Core invocation consolidation** plus a **new deployment shape**. The wire format (CTN, PLACE, IN, ACTS, FEDERATION) is byte-identical to 5.0; what changes is the PHP reference's Core-API surface and the file-layout signal that selects between full and headless deployment shapes.

What's promoted:

- **`$C->ctn()` becomes the single Core invocation method.** Resolution order: registered mod → exact static-container id → bare-stem fallback. Strict-schema enforcement (defaults + required) runs once at the Core's invocation boundary, not at every Stacker call site. Always returns a CTN string — never throws, never `false`, never plain error text. Failures return a typed `CTN: error` block (`code: 400` schema, `code: 404` missing id, `code: 500` throw or ambiguous stem). The 5.0 two-method shape (`$C->mod()` light-filtering + `$C->ctn()` loud-throwing) collapses into this.
- **IN-SPEC 1.0 → 1.1.** Grammar unchanged. §5.4 clarification: enforcement is a Core responsibility; the duplicated Stacker-side pre-filtering of pre-5.1 PHP-reference shape is non-conformance. Strictly additive at the spec level.
- **ACTS-SPEC 1.1 (draft) → 1.2 (draft).** §5.3 source-opaque-dispatch clarification (`error` act handles Core-emitted and mod-emitted blocks byte-identically — already true in practice, now spelled out). §7.2 new normative exit-code contract for CLI Stackers: process exit `1` when the returned CTN stream begins with `CTN: error`, exit `0` otherwise.

What's new:

- **Headless deployment shape.** Detect via `CONTAINERIST_HEADLESS` constant, auto-defined at boot when the repo root has no `places/` directory or it is empty. Headless instances route every URL through `CtnStacker`; no `places/`, no `skin/`, no acts needed. File-layout-as-config — same pattern that detects mods/containers/places.
- **Web Stacker decomposition.** `WebStacker.php` (386 lines, two roles) splits into `PlaceStacker.php` (place pipeline: PRG, dispatcher selection, acts dispatch, page-wrap) and `CtnStacker.php` (single-container URLs: `.ctn` / `.raw` / `.htmx`-mod). Role-named, web realm implicit. Pillar 8 wording extends: "one Stacker per realm" relaxes to "one Stacker per (realm, role) pair."
- **CLI Stacker promoted to class form.** `core/stackers/cli/dispatch.php` (procedural) → `core/stackers/cli/CliStacker.php` (class). Argv parsing unchanged. Exit-code contract per ACTS-SPEC §7.2 implemented: `return (strncmp(ltrim($result), 'CTN: error', 10) === 0) ? 1 : 0;` after the stdout echo.
- **`migrate-mod-to-ctn` codemod.** `core/tools/migrate-mod-to-ctn.php` performs a mechanical `$C->mod(` → `$C->ctn(` and `$core->mod(` → `$core->ctn(` sweep over instance code (`modules/`, `mods/`, `test/`, `lib/` by default; `core/` skipped). Dry-run mode prints proposed edits without applying.
- **`CTN: error` block emission sanitization.** Newlines in `$id` or in exception messages are stripped before interpolation — they previously could have injected sibling frontmatter fields, breaking the block's wire shape.

What's removed (breaking at the PHP-reference Core API level):

- **`$C->mod($name, $args)`.** All callers migrate to `$C->ctn($id, $args)`. No transitional alias — clean cut. Codemod ships at `core/tools/migrate-mod-to-ctn.php`. The internal "mod" naming for the storage form (a PHP file that produces CTN) is preserved; introspection methods (`mod_exists`, `mod_path`, `is_container_mod`, `mod_output_type`) and read-only properties (`mods`, `container_mods`, `mod_types`) keep `mod_` naming.
- **`core/stackers/web/WebStacker.php`** → renamed to `PlaceStacker.php`, with `.ctn` / `.raw` / `.htmx`-mod paths extracted to the new `CtnStacker.php`.
- **`core/stackers/cli/dispatch.php`** (procedural file) → replaced by `core/stackers/cli/CliStacker.php` (class).

Behavior changes worth naming:

- **Mod-to-mod calls now strict.** `$C->ctn('other', $args)` enforces the callee's `@in` and produces a `CTN: error` block on missing required. Pre-5.1 `$C->mod()` would intersect-key-filter and silently run the callee with whatever made it through — masking latent bugs in the call site's arg-supply contract. Audit step after upgrading: run smokes; new `CTN: error` blocks trace back to call sites that need cleanup.
- **Ambiguous container stem now returns `CTN: error code 500`** instead of `E_USER_ERROR` (PHP fatal). Registration-time `error_log()` warning unchanged.
- **CLI exit code derived from leading block type.** Exits 1 when the returned CTN stream begins with `CTN: error`. Output on stdout is unchanged — errors are typed output (Pillar 11). Argv parse errors and pre-Core failures stay on stderr with exit 1.

What's unchanged:

- `CTN-SPEC.md`, `PLACE-SPEC.md`, `FEDERATION-SPEC.md` — Core-API restructuring doesn't touch block format, place declarations, or federation.
- The eight pillars — Pillar 8 gets one clarifying sentence (`(realm, role)` pair); the boundary itself doesn't move.
- Mod authoring — `@in` declarations, `@args`, CTN emission, skin pairs — every mod from 5.0 runs unchanged at 5.1 once the call shape is migrated.
- Federation, acts dispatch contract (§4), the seven core acts (§5), session/header-resolved facts (§6).
- CLI argv parsing — `./ctnr <mod> key=val` and the positional/JSON forms work identically; only the file shape changes (procedural → class) and the exit-code contract is added.

Migration: see `RELEASE-5.1.md` (three steps — codemod, sync core, audit mod-to-mod calls). Trigger context, the back-promotion arc, and the second-instance-first reckoning: see `logs/2026-05-26-5-1-back-promotion.md`.

---

## 5.0.0 — Places replace stacks *(breaking)*

Lands the **place format** as the canonical surface-declaration format. `STACK-SPEC.md` is archived (moved to `archive/4.x-stack-format/`); `.txt` stack files are no longer read by 5.0 reference implementations. The 4.x → 5.0 cut is the first genuine breaking change since 4.0 — instances must migrate `stacks/*.txt` → `places/*.place` before shipping 5.0.

What's promoted:

- **`PLACE-SPEC.md` 0.1 (draft) → 1.0 (normative).** Scope expanded from "AI-realm surface" to canonical surface format for all realms. Lifts URL → place resolution (formerly STACK-SPEC §4), `@args` binding grammar (formerly STACK-SPEC §6.1–6.3), and `@federation` directive (formerly STACK-SPEC §6.4) into PLACE-SPEC.
- **CTN-headed YAML form codified.** A place file is a CTN document of type `place`; the first block's body is the YAML declaration. Unifies the parser path with the rest of Containerist; the file is recognizable as a place from its first line, independent of extension.
- **`.place` extension canonicalized.** 0.1 had specified `.yaml`; 1.0 specifies `.place`. The dedicated extension cleanly separates surface declarations from any other YAML files an instance carries.
- **`places/` directory canonicalized.** 4.x's `stacks/` is renamed to `places/`. The directory and file extension now agree, resolving the two-format-window ambiguity surfaced by the 2026-05-21 complexity evaluation.

What's new:

- **Multi-region surfaces.** A place can declare multiple named regions (`nav`, `tools`, `main`, …), each with its own composition mode (`fixed`, `constrained-fluid`, `open-fluid`) and container list. Fixed regions are the stack-equivalent shape; fluid regions are LLM-composed surfaces not expressible in `.txt`.
- **`dispatcher:` selector.** Places choose between `buffered` (default, the 4.x semantics) and `streaming` (incremental writes to the wire as containers produce). Mods MUST NOT branch on which dispatcher invoked them.
- **`candidates:` for fluid regions.** Distinct from `containers:` (always-included). `candidates:` is the LLM accept-list.
- **URL suffix renames.** `.stack` → `.place`, `.stackctn` → `.placectn`. Implementations MAY accept the 4.x names as compatibility aliases during the migration window.

What's improved (additive, non-breaking inside 5.0):

- **Container-stem-collision warning uses `error_log()` instead of `trigger_error()`.** When two static-container files share the same basename in different subdirectories, the Core logs the ambiguity at registration time. 5.0 switches the channel from `trigger_error(E_USER_WARNING)` (which under `display_errors=on` emits to the response stream and breaks downstream header-setting) to `error_log()` (host log / PHP stderr). The warning is still surfaced; it just stops polluting the output. Surfaced by konnexus.net's 654 import-staging duplicate stems flooding the response stream and breaking 5 instance-smoke status-code checks. The hard `E_USER_ERROR` in `$C->ctn()` for ambiguous lookups stays unchanged. PHP-only; TS/Go ports stay at their own warning conventions.

- **Auto-PRG gains two bypasses for API endpoints. `PLACE-SPEC.md` bumps to 1.1.** The 5.0 default — every non-HTMX POST to a place URL is auto-PRG'd — is correct for browser-driven form submissions but breaks place-routed JSON / multipart / Bearer-auth APIs that return synchronous response bodies. Two complementary bypasses ship:
  - **Bearer-auth bypass** (`ACTS-SPEC.md` §7.1): when the request carries `Authorization: Bearer …`, the WebStacker dispatches the POST directly. Same shape as the existing HTMX-Request bypass — the client signals it expects a body, not a redirect. Automatic; no per-place config needed for the typical M2M case.
  - **Per-place `prg:` key** (`PLACE-SPEC.md` §6.5; new): the place author declares `prg: off` in the frontmatter to bypass PRG explicitly. Values: `auto` (default — current behavior) or `off`. Covers session-auth JSON APIs and other write surfaces where the bearer bypass doesn't apply. Validation rule added to §12 (rule 11); allowed-top-level-keys list extended.
  Surfaced by pond-mk4's three place-routed API endpoints (`/ingest` Bearer-auth, `/pond-drop-edit` and `/pond-upload-submit` session-auth) all regressing to 303 under unconditional auto-PRG during step-6 propagation. After both bypasses, pond's instance smoke runs 22/22 clean. PLACE-SPEC version 1.0 → 1.1 (strictly additive — places without the key see no behavior change). Framework version stays 5.0.0 since both patches fold into the 5.0 release.

What's removed (breaking):

- **`.txt` stack reading.** The 5.0 reference's `WebStacker` no longer opens `.txt` files in `stacks/` or `places/`. An instance running on 5.0 must have migrated. The reference ships `core/tools/stack-to-place` as a one-shot mechanical converter.
- **Implementation symbols.** Framework constants and mod names that referenced "stack" are renamed:
  - `STACKS_DIR` → `PLACES_DIR`
  - `core/mods/stack-ctn.ctn.php` → `core/mods/place-ctn.ctn.php`
  - `core/mods/stack-source.ctn.php` → `core/mods/place-source.ctn.php`
  - `core/mods/skinner-stack.html.php` → `core/mods/skinner-place.html.php`
  - `core/lib/containerist/stack_ref_parser.php` → `core/lib/containerist/place_ref_parser.php`
- **PLACE-SPEC 0.x permissiveness.** The 0.x permission for breaking-without-major has ended at promotion. 1.x changes follow standard semver shape (additive = minor, breaking = major).

What's unchanged:

- `CTN-SPEC.md`, `IN-SPEC.md`, `ACTS-SPEC.md`, `FEDERATION-SPEC.md` — surface-format change doesn't touch block format, mod inputs, act dispatch, or federation.
- The Stacker concept and Core/realm boundary. What the Stacker reads changed; how it composes is the same.
- The eight pillars. Pillar 2 ("Pages = flat stacks") is restated as "Pages = places" with no semantic shift — a place with one fixed region is the same shape as a stack ever was. Pillar 4 ("URL = stack variable scope") becomes "URL = place variable scope" with identical mechanics.
- Mod authoring. `@in`, `@args`, CTN emission, skin pairs — every mod written for 4.x runs unchanged in 5.0.

Migration: see `PLACE-SPEC.md` §13. Trigger context, validating prototype, and lessons: see `logs/2026-05-21-5-0-places.md`.

---

## 4.4.6 — Arg-lifecycle helper extraction

Extracts the duplicated arg-lifecycle position-4-through-6 assembly into a new lib at `core/lib/containerist/arg_lifecycle.php`. Two pure helpers — `arg_lifecycle_resolve_request_facts($get, $post)` and `arg_lifecycle_merge($base_args, $facts)` — replace ~50 lines of duplicated logic across four call sites (`WebStacker::handle()`, `WebStacker::build_mod_args()`, `WebStacker::emit_stack_ctn()`, and the merge block inside `core/mods/stack-ctn.ctn.php`).

Background: 4.4.2 closed an arg-lifecycle realization gap (positions 5–6 not wired through stack-ctn). The patch worked, but the underlying duplication that *enabled* the gap to open in the first place — three near-identical assembly blocks in WebStacker plus a separate merge block in stack-ctn — remained. 4.4.6 collapses all four into one canonical path. ACTS-SPEC §6 has one lifecycle definition; the impl now has one implementation.

`WebStacker.php` shrinks 446 → ~410 lines. `stack-ctn.ctn.php` shrinks ~12 lines. Behavior strictly unchanged — every smoke that exercised lifecycle precedence on stack-routed and URL-suffix dispatch paths in 4.4.2+ continues to pass byte-identically.

Strictly additive: no spec text change (ACTS-SPEC §6 was already correct), no API surface change at the stack-ctn or WebStacker boundaries, no instance-config interaction. ACTS-SPEC stays at 1.1 (draft). Surfaced during the 2026-04-30 LLM-first review pass; the structural fix flagged for the WebStacker-growth concern.

---

## 4.4.5 — Acts contract consolidation

Adds **`ACTS-in-PHP.md`**, the missing PHP sidecar for the act dispatch layer, and clarifies **ACTS-SPEC §4.4** to acknowledge the third implementation-specific context argument both PHP and TS reference impls already pass.

Background: `ACTS-in-TS.md` has existed since the 4.3 acts work; `ACTS-in-PHP.md` did not. The PHP-specific act conventions — scope-inheritance contract (`$block`, `$response`, `$C` arrive via include scope), the four MUST NOT rules (no top-level function, no `return $value`, no `echo`/`print`, no realm globals), the PHP-array shape of the response state — were duplicated across seven `acts/*.php` file headers and the `WebStacker::dispatch_acts()` doc-comment. The new sidecar consolidates them into one place. Each act file's header now references this sidecar plus the per-act ACTS-SPEC §5.x entry, and adds only what is genuinely act-specific.

Spec change is strictly clarifying: §4.4 already required two normative arguments; the §4.4 addendum names that impls MAY pass a third for impl-specific context (Core handle, root paths). Both reference impls already do this. ACTS-SPEC stays at 1.1 (draft) with a fourth bullet added to the existing 1.1 revision-trail entry. The "Third-arg deviation" the TS sidecar self-flagged as "Candidate for a future spec revision" is now lifted.

Strictly additive: no behavior change, no act-file functional edits, no new lint rule. All 1.0/1.1-conformant Stackers and act files keep conforming. Surfaced during the 2026-04-30 LLM-first review pass; closes a documentation drift the journal had repeatedly flagged ("true in intent, false in code").

---

## 4.4.4 — `config.local.php` convention

Adds the **`config.local.php` convention** for environment-specific overrides. The framework's `core/containerist.php` now loads `ROOT_DIR/config.local.php` (if present) BEFORE `config.php`, so local overrides win — `if (!defined)` guards in `config.php` ensure local values stick.

Use case: development settings (`TRACE_ENABLED=true`, dev domain, dev base URL) live in `config.local.php` (gitignored, host-specific); production settings stay in `config.php` (committed, deployed). Prior to 4.4.4 instances toggled `TRACE_ENABLED` and similar by editing `config.php` and risking that edit reaching production.

Strictly additive: instances without a `config.local.php` see no change. Migration for instances that want the convention: add `if (!defined)` guards around their existing `config.php` defines, then create `config.local.php` for their dev overrides.

---

## 4.4.3 — `SKIN_WEBBASE` config constant

Adds the **`SKIN_WEBBASE` config constant** alongside `SKIN_DIR`, splitting the skin's filesystem path from its URL prefix, and threads it through every framework consumer that emits skin-asset URLs: the `stylesheet-links` mod (per-block-type CSS hrefs) and the reference `skin/stack.html` page-shell template (the htmx script tag).

The `page-wrap` mod passes `webbase` into the page-shell so authors of custom page-shells reference `{{webbase}}htmx.min.js` and `{{webbase}}<asset>` cleanly. Prior to 4.4.3, both consumers hardcoded `/skin/`, which worked only when an instance kept the framework default skin layout. Instances with non-default skin paths (e.g. pond's `skins/meridian/`) now set both constants in `config.php` and inherit a working page-shell without further customization.

Strictly additive: no spec text changes, defaults preserve existing behavior, sites that don't override `SKIN_WEBBASE` see no change. Surfaced during pond's port; one `FRAMEWORK GAP` comment removed.

---

## 4.4.2 — Arg lifecycle realization gap closed

Closes the **arg lifecycle realization gap** (`ACTS-SPEC.md` §6). Positions 5 (query string) and 6 (POST body) of the precedence list were already documented but only realized on URL-suffix dispatch paths (`.ctn`, `.raw`, `.htmx`); stack-routed URLs (no-suffix) saw only positions 1–4.

4.4.2 wires `query` and `post` through the WebStacker → stack-ctn chain so the lifecycle is identical regardless of dispatch path. Result: stack-routed POST endpoints (form submits, JSON body posts where the mod parses) declare `@in: drop_id, action, …` and receive POST fields automatically.

Strictly additive: ACTS-SPEC stays at 1.0, mods that don't declare names matching query/POST keys see no behavior change. Pond's auth flow + write APIs gain pillar-8-clean shapes via this fix.

---

## 4.4.1 — Header-resolved facts

Adds **header-resolved facts** (`ACTS-SPEC.md` §6.1) — a sibling resolver to the existing session-resolved facts, populating arg-lifecycle position 4 from request headers instead of the cookie session store. Ships one fact: `bearer`, drawn from `Authorization: Bearer <token>` (RFC 6750).

Mods declare `@in: bearer (optional)` and receive the token; ingest endpoints, webhooks, and machine-to-machine APIs no longer need to reach for `getallheaders()` from inside mod bodies (preserving pillar 8).

Strictly additive: ACTS-SPEC stays at 1.0, the §8 conformance clause is unchanged, mods that don't declare `@in: bearer` see no behavior change. Framework lib lives at `core/lib/containerist/auth_header.php`; demo mod is `bearer-whoami`.

---

## 4.4 — Federation v2.0

Lands **federation v2.0** (`FEDERATION-SPEC.md` v2.0). Direct federation (v1, shipped earlier) keeps working; new to 4.4:

- **Fed-rebase** (`@federation` stack-frontmatter directive, three URL-resolution policies, resource URLs always to producer)
- **Deferred federation** (`htmx: http(s)://…` stack lines, consumer-mediated fragment endpoint at `/fed.htmx`, TTFB-preserving)
- Block denylist stripping control-flow blocks from federated responses
- Cache key includes policy
- `origin:`-bearing error blocks no longer mutate the consumer page's HTTP status

Purely additive at the authoring surface — existing v1 federation stacks work unchanged; new capabilities are opt-in via directives.

---

## 4.3 — Acts dispatch layer

Introduces the **acts dispatch layer** at the Stacker level (`ACTS-SPEC.md`). Seven core acts — `skin`, `redirect`, `error`, `content-type`, `session`, `flash`, `title` — with `skin` as the default. `session` and `flash` land auth without middleware; `title` finally lets content-knowing mods populate the page `<title>` element (long-standing Containerist gap closed).

Additive for sites that don't emit the new blocks; existing stacks, mods, and skin pairs keep working unchanged.

---

## Earlier history

For the 4.0–4.2 arc and the architectural decisions that produced the eight pillars, read `briefing-detailed.md` (especially §3 "Lineage") and the relevant entries in `logs/2026-04-1*.md`.
