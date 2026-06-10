# Containerist — Operator briefing

containerist_version: 5.2.2
document_version: 3.3

*Task-time reference for building and maintaining Containerist-based sites. For why Containerist exists, read `mission.md`. For full architectural rationale, read `briefing-detailed.md`. For wire-format authority, read `CTN-SPEC.md`, `IN-SPEC.md`, `PLACE-SPEC.md`, `ACTS-SPEC.md`, `FEDERATION-SPEC.md`; for the optional CTN-over-HTTP adapter, `WIRE-SPEC.md`. Per-language implementation notes: `CTN-in-PHP.md` / `CTN-in-TS.md` / `CTN-in-Go.md`, `IN-in-*.md`, `archive/4.x-stack-format/STACK-in-*.md` (4.x-only; pending PLACE-in-*.md sidecars for 5.0), `ACTS-in-PHP.md` / `ACTS-in-TS.md`, `FEDERATION-in-PHP.md`. For the version arc that produced the current shape, read `CHANGELOG.md`.*

*5.0 lands the **place format** as the canonical surface declaration. The `.txt` stack format is retired; URL → place resolution, `@args` binding, and `@federation` directives all live in `PLACE-SPEC.md` now. A place with one `fixed` region is the direct equivalent of a 4.x stack; new to 5.0 are multi-region surfaces (`fixed` + `constrained-fluid` + `open-fluid`), the `dispatcher:` selector (buffered default, streaming opt-in), and the storage-directory rename `stacks/` → `places/`. The 4.x stack-format spec lives on at `archive/4.x-stack-format/STACK-SPEC.md` for anyone porting from 4.x; instance migrations are mechanical (see PLACE-SPEC §13).*

*5.1 collapses the Core's two invocation methods into one — **`$C->ctn($id, $args)`** is now the only way to invoke a container: strict-schema-enforced, always returning a CTN string (a `CTN: error` block on any failure). The Core API method `$C->mod()` is removed — a codemod ships at `core/tools/migrate-mod-to-ctn.php`. A new deployment shape — **headless** — lets an instance ship without `places/`, `skin/`, or acts (detected from the missing `places/` directory at boot): federation producers, BFFs, CLI-consumed services. The wire format is byte-identical to 5.0. See `RELEASE-5.1.md` for the upgrade path.*

*5.2 re-architects the realm/render layer around CTN as the waist. The 5.1 Web Stacker split (`PlaceStacker` / `CtnStacker` / `CliStacker`) is retired: a realm-free, format-free **Core** produces all CTN; **renderers** turn CTN into format bytes; thin per-realm **adapters** do I/O. Flow: `request → adapter → core → renderer → adapter → response`. Wire format and the acts dispatch contract are unchanged — acts become the renderer's realm-neutral block-handlers. Behavior-preserving: every URL, suffix, and rendered byte is identical. See `RELEASE-5.2.md`.*

*Alongside 5.2.2, **`WIRE-SPEC.md` 0.1 (draft)** lands as the first OPTIONAL adapter spec: the **wire adapter**, CTN-over-HTTP — the waist exposed as a product surface for rich clients, device renderers, and scripts. `.spec` serves a place's structure as one `CTN: place` block; `.ctn` serves/relays containers verbatim (mutations follow the P2 pattern: a `CTN: flash` describing what happened + fresh content blocks); a consumer-opted `_return` field gives no-JS forms Post-Redirect-Get. No core spec changes; no framework version change — the spec documents the already-shipped Go adapter (`containerist-go/adapters/spa`), extracted the way CTN-SPEC was extracted from the PHP parser. PHP/TS do not implement it (a conformance-table fact, not an upgrade obligation). See `logs/2026-06-10-wire-spec.md`.*

---

## What it is

Containerist is a modular web framework. Sites are **places** of named containers (composed by region into CTN by the Core, turned into output by a renderer, delivered by a realm adapter); mods produce typed CTN blocks; skins render them. No database, no build step, no component trees, no Composer. Pillars 1–4 are language-independent (spec); pillars 5–8 are per-implementation. Current implementations: PHP (mature, 5.0), TS (peer, 4.x catching up), Go (in progress).

## File layout

Every PHP Containerist site is a repo-root tree with a `core/` subtree shipped from the reference. Repo root holds the site's places, mods, skins, containers, and entry points; `core/` holds the framework.

| Path | Contents |
| --- | --- |
| **Repo root (site-owned)** | |
| `index.php` | Web entry — thin **web adapter** boot (~30 lines: loads Core, instantiates the web adapter, which parses the request, picks a renderer by URL suffix, calls Core, and delivers). 5.2+: headless instances resolve only single-container / raw-renderer URLs (no `places/` → no place renderer). |
| `cli.php`, `ctnr` | CLI entry + executable wrapper (`./ctnr <mod> key=val`). 5.2+: the **cli adapter** (`CliAdapter`) — always the raw renderer (CTN → stdout) + exit-code. |
| `config.php` | Instance config — site identity, env toggles. Small by design; framework defaults live in `core/config.defaults.php`. Defines SHOULD use `if (!defined)` guards so a per-host `config.local.php` (4.4.4+) can override. |
| `config.local.php` | Optional per-host overrides (gitignored). Loaded by `core/containerist.php` BEFORE `config.php`, so local defines win. Typical use: `TRACE_ENABLED=true` for dev, alternative `DOMAIN`/`SITE_URL` for staging. |
| `places/*.place` | Place files (URL → composed surface; see `PLACE-SPEC.md`). 5.0+ — replaces 4.x's `stacks/*.txt`. |
| `modules/<ns>/*.<type>.php` | Instance mods (e.g. `modules/demo/`, `modules/pages/`). Namespace is a subfolder under `modules/`. |
| `containers/**/*.txt` | Static containers (plain CTN blocks); recursive, flat id-space by filename stem. |
| `skin/<type>.{html,css}` | Skin pair per CTN type. Optional third member `skin/<type>.js` when the block requires client-side interaction (htmx-driven toggles, focus handling, etc.). |
| **`core/` (framework, shipped from the reference)** | |
| `core/containerist.php` | **Core (engine)** class (`Containerist`): `id + args → CTN`. Registers mods + containers; composes containers *and* places into CTN; executes mods under `@in` schema. Realm-free **and** format-free. |
| `core/config.defaults.php` | Framework defaults; instance `config.php` overrides via `if (!defined)` guards. Path constants split filesystem path from URL: `SKIN_DIR` (filesystem; default `ROOT_DIR/skin/`), `SKIN_WEBBASE` (URL prefix for skin assets; default `/skin/`), `PLACES_DIR` (5.0+; default `ROOT_DIR/places/`). |
| `core/renderers/` | **Renderers** (5.2+): CTN → realm-neutral response. `dispatch.php` (block-dispatch loop), `acts/` (per-block handlers — skin · redirect · session · flash · content-type · title · error; realm-neutral), `html/` (HTML body assembly: page-wrap + buffered/streaming strategies), `raw/` (raw CTN passthrough for `.ctn` / `.raw` / `.placectn` — skips dispatch), `source/` (`.place` source dump). Realm-free; format-specific. (`text/` reserved; deferred.) |
| `core/adapters/web/` | **Web adapter** (5.2+; was `core/stackers/web/`): request parse (inbound) + realm-fact resolution (session / header / query / POST) + response emit (outbound) + `Trace.php` (dev-only, gated by `TRACE_ENABLED`). The realm edge — the only layer touching `$_GET` / `header()` / `$_SESSION`. |
| `core/adapters/cli/CliAdapter.php` | **CLI adapter** (5.2+; was `core/stackers/cli/CliStacker.php`). Parses argv, invokes `$C->ctn()`, routes the CTN through the raw renderer to stdout. Exits `1` when the returned CTN begins with `CTN: error`, `0` otherwise. No session, no HTTP headers, no page wrap. |
| `core/mods/*.php` | Framework pipeline mods: `place-ctn`, `skinner-ctn`, `skinner-block`, `skinner-place`, `page-wrap`, `stylesheet-links`, `place-source`. |
| `core/lib/` | Framework libs: `yaml/` (Spyc), `mustache/`, `parsedown/`, `path/`, `common/tools.php`, `containerist/ctn_in_parser.php`, `containerist/place_ref_parser.php`, `containerist/route_resolver.php`. |
| `core/tools/` | Linter (`lint.php`), CLI smoke (`smoke.sh`), HTTP smoke (`smoke-http.sh` — spins up `php -S`, exercises all seven acts + session/flash round-trip + dispatcher variants), `stack-to-place.php` migration helper (5.0+, transitional), `migrate-mod-to-ctn.php` (5.1+, mechanical `$C->mod(` → `$C->ctn(` rewrite for instance code). |
| `core/docs/` | This briefing + wire-format specs (`CTN-SPEC`, `IN-SPEC`, `PLACE-SPEC`, `ACTS-SPEC`, `FEDERATION-SPEC`) + PHP implementation notes (`*-in-PHP.md`, including the new `PLACE-in-PHP.md` at 5.0). The deprecated `STACK-SPEC.md` and its PHP sidecar live at `archive/4.x-stack-format/` in the meta repo, not mirrored into instances. |

### Entry-point pattern (5.2+)

The three repo-root entry points (`index.php`, `cli.php`, `ctnr`) are **framework-shaped**: the reference ships canonical versions that instances start from and customize sparingly. Each entry point is a thin **adapter** boot — it loads the Core, instantiates the realm adapter, and the adapter conducts the rest. The adapter is the **conductor**: Core and renderer never call each other. The adapter parses the request, **chooses the renderer by URL suffix**, calls Core for the CTN, hands that CTN to the chosen renderer, then delivers the resulting response. This is the realm-aware code; Core and renderers are realm-free.

**Web entry (`index.php`).** The web adapter selects, by URL suffix, *what the Core produces* (a container or a place) and *which renderer* formats it:

- `.ctn` / `.raw` → one **container**, **raw** renderer (passthrough; skips act-dispatch).
- `.placectn` → the matched **place**, **raw** renderer (combined pre-skin place CTN).
- `.place` → the place-file **source**, **source** renderer (`text/plain`).
- `.htmx` *with mod-match* (URL last-part names a registered mod, checked via `$C->mod_exists($stem)`) → one **container**, **html** fragment renderer (no page-wrap).
- `.htmx` *without mod-match* → the matched **place**, **html** fragment renderer (fall-through; place serves the fragment).
- no suffix / `.html` → the matched **place**, **html** renderer + page-wrap (full skinned page).
- `.trace` → dev diagnostic (gated by `TRACE_ENABLED` / `?trace=1`).

Headless instances have no `places/`, so no place renderer resolves: only the container-producing / raw paths answer; every place URL is a 404. The reference's canonical `index.php` is ~50 lines including the static-file passthrough for `php -S`.

**CLI entry (`cli.php` + `ctnr`).** `ctnr` is a bash wrapper that execs `cli.php`. `cli.php` defines `ROOT_DIR` / `CORE_DIR`, loads the Core, instantiates `CliAdapter`, and exits with `$adapter->handle($argv)`'s return code. The cli adapter always uses the raw renderer (CTN → stdout); no suffixes. Exit-code contract per ACTS-SPEC §7.2 — `1` when the returned CTN begins with `CTN: error`, `0` otherwise.

**Instance customization.** Common shapes:

- *Auth gate.* Instances that need per-place authentication add a route-resolver + auth check in the web adapter entry, before the place renderer runs (after the container/raw paths return). Pattern: read the matched place file's `auth:` field, redirect to login if unauthenticated. Lives in instance code (`lib/auth.php` or similar); the framework provides the read mechanism, not the policy.
- *URL rewrites.* Instance-specific 301s or canonicalizations (e.g. legacy URL → new URL) live in `index.php` before the framework routing, typically near the static-file-passthrough block.
- *Subdomain or path prefix routing.* Multi-site instances may wrap the framework routing in a domain-or-prefix check that selects a different `ROOT_DIR` or instance config.

What MUST stay in the canonical shape: the suffix → renderer selection, the headless behavior (no place renderer), the `.htmx` mod-existence check, the CLI exit-code derivation. Diverging from these on an instance basis is a drift signal — the adapter encodes the Pillar 8 boundary, and the entry-point routing is the realm-aware code that respects it. Adding to the framework happens by promoting the new pattern to the reference's `index.php`, not by per-instance entry-point divergence.

## Pillars (architectural invariants)

Boundaries for task-time work. Full rationale in `briefing-detailed.md`.

**Format (spec-level, language-independent)**

1. **CTN block** — `CTN: type` + YAML frontmatter + `---` + optional body. Plain text without a `CTN:` header is an implicit `standard` block.
2. **Pages = places** — `places/*.place` declares the surface as one or more named **regions**, each with a composition mode (`fixed`, `constrained-fluid`, `open-fluid`) and a container list. A place with one `fixed` region is the direct equivalent of a 4.x stack — a flat list of containers; multi-region surfaces extend the format to LLM-composed compositions. No nesting, no conditionals, no computed inclusion: places are data, not code. (Internally, a fixed region's ordered container list is still called a *stack* — lowercase, part-of-a-place.)
3. **Output = flat typed blocks** — each block type renders via its `skin/<type>.{html,css}` pair. No component trees, no recursion, no layout engine.
4. **URL = place variable scope** — priority-sorted wildcard match; `@args` frontmatter binds URL parts to named inputs.

**Processing (runtime-level, per-implementation)**

5. **Mod = typed modifier** — declared `@in` inputs; filename declares output type (`.ctn.php`, `.html.php`, etc.).
6. **Uniform invocation** — same mod runs from HTTP (`/mod.ctn`), CLI (`./ctnr mod`), in-process (`$C->ctn('mod', $args)`). Same args, same output.
7. **Containers are namespace-flat** — mod and static file share one namespace; dispatcher resolves mod-first, static fallback.
8. **Core is realm-free** — Core has no `$_GET` / `$_POST` / `header()` and makes no realm-dependent decision. The **adapter** is the realm-aware layer: it parses the request, resolves the realm's facts, routes by suffix, and emits the `raw` / `source` responses, redirects, and 404s. Renderers make no realm *decisions*; the `raw` and `source` renderers hand their response to the adapter to emit, while the **html** renderer performs its own HTTP emit — it carries the streaming strategy, which flushes incrementally and can't defer a finished response to the adapter. That emit is mechanical I/O of effects the acts already described (write the queued session mutations, set headers, send the body), not realm logic. (5.2+: one adapter per realm — web, cli, future mcp/sse.)

## Vocabulary

- **CTN block** — fundamental content unit. Typed, parseable, composable.
- **Container** — source of CTN blocks. Either a mod or a static file.
- **Mod** — short for *modifier*. PHP file at `modules/**/*.<type>.php`. Emits CTN via `print`/`echo`; return values are ignored.
- **Place** — URL-resolvable composition of one or more regions. File at `places/*.place`. Matched by URL parts via priority-sorted wildcard (`foo--bar` > `foo--*` > `*--bar` > `*--*`). The canonical surface-declaration format (5.0+). A place is a CTN document of type `place` whose body is a YAML declaration. Spec: `PLACE-SPEC.md`.
- **Region** — named area within a place, with its own composition mode (`fixed` / `constrained-fluid` / `open-fluid`) and container list. Regions are siblings, not nested. A place with one `fixed` region is the stack-equivalent shape.
- **Composition mode** — per-region rule for what determines the container list. `fixed` = author-declared; `constrained-fluid` = LLM-selected from an accept-list (`candidates:`); `open-fluid` = LLM-selected from the full library. See PLACE-SPEC §7.
- **Dispatcher variant** — per-place selector (`dispatcher: buffered` default, `dispatcher: streaming` for incremental writes). Buffered collects all output then sends; streaming emits incrementally as containers produce. Mods MUST NOT branch on which dispatcher invoked them. See PLACE-SPEC §9.
- **Stack** — lowercase, part-of-a-place: the ordered container list of one `fixed` region. (Capital-S *Stack* was the 4.x top-level surface format, archived at `archive/4.x-stack-format/STACK-SPEC.md` after 5.0.)
- **Skin** — the render artifact: one `.html` + one `.css` per CTN type, applied by the `skin` act.
- **Act** — renderer-layer handler for one CTN block type. One file (`acts/<stem>.php`), one block type. The `skin` act is the default (any block without a matching act is rendered via its skin pair). Other core acts: `redirect`, `error`, `content-type`, `session`, `flash`, `title`. See `ACTS-SPEC.md`.
- **Core (engine)** — the CTN-producing subsystem: `id + args → CTN`. Registers/executes mods, parses CTN, resolves container names, composes containers *and* places. **Realm-free AND format-free.** Proper noun (capital C). The waist's upper half — everything above CTN that *makes* CTN.
- **Renderer** — `CTN → realm-neutral response` (body bytes + an effect-description: status, headers, session-mutations, redirect, flash, head-slots). Makes no realm decision; **format-specific**: `html` (skins blocks, page-wraps), `raw` (CTN passthrough — skips act-dispatch), `source` (`.place` file text), and `text` (terminal-readable; reserved, deferred). Owns the acts dispatch (the acts are its per-block handlers) and the dispatcher-variant (buffered/streaming) selection. `raw` / `source` return their response to the adapter to emit; `html` performs its own emit (the streaming dispatcher flushes incrementally). The waist's lower half — everything below CTN that *turns CTN into output*.
- **Adapter** — the realm edge: receives the realm-native request, **conducts** (picks the renderer by suffix, calls Core, routes the CTN through the renderer), and delivers (performs the realm-neutral response in the realm — HTTP status/headers/session/body or redirect; CLI stdout + exit-code). One per realm (`web`, `cli`; future `mcp`, `sse`). The only realm-aware layer. Talks to Core only via `$C->ctn()`. (Replaces the 5.1 Stacker classes; the textbook Adapter pattern, used correctly — it *adapts* a realm to the realm-free Core.)
- **`@in`** — mod input contract, declared as line-2 comment: `// @in: name1 (required), name2 (default: 10), name3 (optional)`.
- **`@args`** — place frontmatter binding URL parts to named args: `@args: note_id = {{2}}`. Per-line args extend the grammar per-invocation.
- **Federation** — cross-origin transclusion. A container-list entry starting with `http://` or `https://` fetches CTN from that URL and inlines it as if produced locally. Allowlist-gated, cached, errors render inline. Spec: `FEDERATION-SPEC.md`.
- **Fed-rebase** — consumer-side rewriting of navigational URLs inside federated content, authored per-place via `@federation` in place frontmatter. Three policies: `off`, `to-producer`, `to-consumer`. Default: `to-producer`. Resource URLs (images, scripts) always resolve to producer. Spec: `FEDERATION-SPEC.md §13`.
- **Direct federation** — the default federation form: `http://…` as a container-list entry; consumer fetches during render.
- **Deferred federation** — `htmx: http://…` as a container-list entry; consumer fetches on a second round-trip after initial page paint via the `/fed.htmx?ref=…` fragment endpoint. Server-side throughout. Faster TTFB. Spec: `FEDERATION-SPEC.md §14`.

## Place syntax

A place file is a CTN document of type `place` whose body is a YAML declaration. Simple form (single fixed region, stack-equivalent):

```yaml
CTN: place
@args: id = {{2}}
---
containers:
  - page-header
  - "note-detail?id={{id}}"
  - "htmx: related-notes?id={{id}}"
  - page-footer
```

Multi-region form (composition modes, dispatcher selection):

```yaml
CTN: place
PLACE: reading
dispatcher: streaming
@args: id = {{2}}
---
purpose: a reading surface for a single note

regions:
  nav:
    mode: fixed
    containers:
      - site-nav

  article:
    mode: fixed
    containers:
      - "article?id={{id}}"

  tools:
    mode: constrained-fluid
    purpose: navigation and connections for this note
    containers:
      - nexus
    candidates:
      - terms-view
      - backlinks
```

- Each container reference: `<name>` optionally followed by `?key=value&key2=value2`.
- `@args` frontmatter binds URL numeric parts (`{{N}}`) or literals to named args.
- `@federation` frontmatter binds producer origins to URL-rebase policies (`off` | `to-producer` | `to-consumer`) for federation refs in this place. Default `to-producer`.
- `dispatcher:` YAML key selects buffered (default) or streaming dispatch. See PLACE-SPEC §9.
- `htmx: <name>` prefix emits an HTMX placeholder block; content loads client-side after page paint.
- A bare `http(s)://…` container-list entry is a direct federation ref.

Full grammar: `PLACE-SPEC.md`. Migration from 4.x stacks: `PLACE-SPEC.md` §13.

## CTN block

```
CTN: article
title: "Hello"
published: 2026-04-20
---
Some Markdown prose.
```

- **Structured data → fields** (YAML frontmatter). Consumers read programmatically.
- **Prose → body** (text below `---`). Skinner renders.
- Anti-pattern: emitting structured data as body text forces every consumer to re-parse. If you find yourself writing a second parser for a body, move the content into fields.

Full grammar: `CTN-SPEC.md`.

## Skin set

One `skin/<type>.html` + one `skin/<type>.css` per CTN block type — the **skin pair**, the default shape. When the block requires client-side interaction (a toggle that POSTs via htmx, focus management, drag, anything beyond static render), the pair extends to a **skin triplet** by adding `skin/<type>.js`. The `.js` lives next to its `.html` and `.css` by name; loading is the page-shell's job (the framework convention is to inject all `skin/*.js` files via a `<script src="…">` tag in the shell's `<head>`, or a `stylesheet-links`-style mod for scripts; instances choose).

Template engine is Mustache: `{{field}}` (HTML-escaped), `{{{body}}}` (raw). No slots, no layouts, no component composition. New block type = new skin set (pair, or triplet when interactive).

The skin set is applied by the **`skin` act** — the default act at the renderer layer. Any block whose type has no matching `acts/<type>.<ext>` file falls through to the skin act, which looks up the set and mustache-renders the HTML. See `ACTS-SPEC.md` §5.1.

The triplet form is reserved for genuine block-level interaction. A `.js` file in `skin/` is part of *one* block type's render contract — never shared across types, never a place for cross-cutting JavaScript. Cross-cutting client logic, when it appears, lives at the instance's discretion (e.g. `skin/site.js`, `lib/`); the convention here is only about block-bound behavior.

## Acts and response assembly

The renderer assembles every mod's emitted CTN into one stream, parses it into a block list, and dispatches each block to its **act** — a one-file handler at `acts/<stem>.<ext>`. One act = one file = one block type. The `skin` act is the default (described above); the other core acts populate the realm-neutral response's effect fields (status, headers, session-mutations, redirect, flash) — they *describe* effects; the adapter *performs* them.

The seven core acts:

| Act | Block emitted by a mod | The act does what |
| --- | --- | --- |
| `skin` (default) | any content block (`article`, `page-header`, …) | look up skin pair, mustache-render, append HTML |
| `redirect` | `CTN: redirect` with `to: /path` | emit 302 (or given status), `Location:` header, short-circuit |
| `error` | `CTN: error` with `code: 4xx/5xx` | set status, render error body if skin exists, continue rendering |
| `content-type` | `CTN: content-type` with `value: <mime>` | set `Content-Type` header |
| `session` | `CTN: session` with `set:` / `clear:` | queue session mutation; applied before response send |
| `flash` | `CTN: flash` with `key:`, `msg:` | queue one-shot message for injection on next request |
| `title` | `CTN: title` with `value: <string>` | write to `response.head["title"]`; page-shell reads `{{head.title}}` |

Acts run in **block order** (the order blocks appear in the assembled stream, which is the order mods appear in the stack body). The renderer short-circuits on the first `redirect`; side effects already queued (e.g. a preceding `CTN: session`) still apply — they ride in the response's effect-description, and the adapter performs them before sending the redirect. Head-slot writes (`title` and future siblings) are last-write-wins by emission order — a more-specific mod later in the stack supersedes a header default.

**Adding a new response behavior = new file at `acts/<stem>.<ext>`.** The renderer's main loop does not grow. Instance can override any core act by dropping in its own file (filename stem wins).

Full grammar, dispatch contract, conformance rules: `ACTS-SPEC.md`.

## URL suffix dispatch

A suffix selects two things at once: **what the Core produces** (a container or a place) and **which renderer** formats it (5.2+).

| Suffix | Core produces | Renderer | Result |
| --- | --- | --- | --- |
| *(none)* / `.html` | the matched **place** | **html** + page-wrap | full skinned HTML page |
| `.placectn` | the matched **place** | **raw** | combined pre-skin place CTN, `text/plain` (5.0+; 4.x used `.stackctn`) |
| `.place` | *(file read)* | **source** | raw `.place` file source, `text/plain` (5.0+; 4.x used `.stack`) |
| `.ctn` / `.raw` | one **container** | **raw** | that container's CTN, `text/plain` |
| `.htmx` (URL last-part names a registered mod) | one **container** | **html** fragment | skinned fragment, no page-wrap |
| `.htmx` (no mod match) | the matched **place** | **html** fragment | place as fragment (fall-through; HTMX bypass of auto-PRG) |
| `.text` | the matched **place** | **text** | terminal-readable text *(reserved; deferred — see `RELEASE-5.2.md`)* |
| `.trace` | the matched **place** | *(dev diagnostic, gated)* | structured dispatch dump (`TRACE_ENABLED=true` or `?trace=1`) |

Symmetry confirms the axes are clean: **raw** × {container, place} = `.ctn` / `.placectn`; **html** × {container, place} = `.htmx` / *(none)* (fragment vs page = "+page-wrap when it's a place"). `source` / `trace` are place-scoped inspection outputs.

Reference implementations MAY accept the 4.x suffix names (`.stack`, `.stackctn`) as compatibility aliases during the migration window. New instances SHOULD use the 5.0 names.

The mod-existence check for `.htmx` is the one routing decision that needs to know about the Core's state; the web adapter consults `$C->mod_exists` to decide between a container (registered mod → html fragment) and the place fall-through. Headless instances have no places, so a place URL has nothing to resolve and becomes a 404.

## Deployment shapes

5.2+ recognizes two deployment shapes, distinguished by file layout:

- **Full** — `places/` directory exists and contains at least one `.place` file. The full set of renderers resolves: place URLs run the **html** renderer (acts, skin, page-wrap) or **source**; container URLs run **raw**.
- **Headless** — no `places/` directory, or no `.place` files in it. No place renderer resolves: only container-producing paths answer (`.ctn` / `.raw` / `.htmx`-mod → **raw** / **html** fragment). Every place URL is a 404. No skin, no acts dispatch, no page-wrap. The instance answers single-container URLs and nothing else.

The detection rule: a Containerist instance is **headless iff its repo root has no `places/` directory, or the directory contains zero `.place` files.** (A `places/` with only non-`.place` files — e.g. a stray `.txt` from a half-done migration — is still headless; the rule keys on `.place` files specifically, not directory non-emptiness.) No config flag. The framework defaults file (`core/config.defaults.php`) defines `CONTAINERIST_HEADLESS` when this condition holds; the web adapter reads the constant.

**Why file-layout-as-config.** The same pattern already detects mods (scan `modules/`), containers (scan `containers/`), and places (scan `places/`). Deployment shape follows the same rule, so an LLM (or human) reading the repo can infer the posture from `ls` alone — no config flag to grep for.

**Typical headless instances.** A federation producer exposing a few `.ctn` endpoints to consumers. A backend-for-frontend serving CTN to a separate UI. A CLI service that accepts HTTP requests as a transport for `./ctnr` invocations. None of these need the place / skin / acts pipeline; headless deployment lets them ship without it.

The Pillar 8 boundary is unchanged in both shapes. The hourglass makes the headless shape natural: with no `places/`, the web adapter simply has no place renderer to invoke — the container/raw paths are all that resolve, and the Core stays the same realm-free, format-free engine either way. The same one-adapter-per-realm shape holds for future realms (MCP, SSE).

## Arg lifecycle

When a mod is invoked through the web adapter, `$args` is assembled in this precedence (later wins on key collision):

1. **URL numeric parts** — `{{1}}`, `{{2}}`, … from the path after suffix strip.
2. **Place `@args`** — named bindings from numeric parts or literals.
3. **Place per-line args** — per-invocation overrides on an individual container ref.
4. **Adapter-resolved facts** — injected by the realm adapter at request start (realm-fact resolution is the adapter's inbound job). Two sibling resolvers populate this position:
   - **Session-resolved** (cookie-bound): `user` (`null` if unauthenticated) plus any `flash` entries written on the prior request, injected under their chosen key name and cleared after read. `ACTS-SPEC.md` §6.
   - **Header-resolved** (request-bound, since 4.4.1): `bearer` from an `Authorization: Bearer <token>` request header, when present. `ACTS-SPEC.md` §6.1.

   Header facts merge after session facts on key collision (request is fresher than stored state). Mods opt in by declaring `@in: <name> (optional)` — undeclared names are not injected.
5. **Query string** — every `$_GET` key except `q` (Apache rewrite key) and `trace`.
6. **POST body** — every `$_POST` key (write-surface mods).

The dict is then schema-checked against the mod's `@in`:

- Required inputs → throw `InvalidArgumentException` if missing (HTTP 400 at web).
- Optional inputs with `default:` → filled if absent.
- Undeclared keys → not extracted into the mod's scope.

Each schema-matched key becomes a local via `extract()`. `$C` (the Core instance) is additionally in scope.

CLI (`./ctnr <mod> key=val`) and in-process (`$C->ctn('<mod>', $args)`) skip steps 1–2 (no URL, no place) and pass args directly to the schema-check.

## Writing a mod

Six things, in order.

1. **Filename = type declaration.** `<name>.ctn.php` for CTN (composable default); `<name>.html.php` for terminal HTML; `<name>.<other>.php` for other typed outputs.
2. **Declare `@in` on line 2** (after `<?php`): `// @in: name1 (required), name2 (default: 10), name3 (optional)`. Undeclared args don't reach scope. `$C` is additionally in scope without declaration.
3. **Emit with `print`/`echo`**; never `return $value`. Top-level `return <expr>;` is silently discarded — the one drift that fails quietly. Use bare `return;` for early exit.
4. **No realm globals.** `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`, `$_COOKIE`, `$_REQUEST`, `$_FILES`, `STDIN` — banned inside a mod.
5. **Explicit `$C->ctn(...)`** — never `$C->foo(...)`. Core has no `__call`. `$C->ctn()` is the single invocation method (5.1+; `$C->mod()` removed).
6. **New CTN type → new skin set.** Add `skin/<type>.html` + `skin/<type>.css` (the pair). If the block requires client-side interaction, add a third file `skin/<type>.js` (the triplet) — never split block-bound JS out of `skin/`. One type = one set = one render path.

## Public Core API

A running mod has `$C` (the `Containerist` instance) in scope.

**Methods**
- `$C->ctn($id, $args)` — invoke a container. The single invocation method (5.1+). Resolution order: registered mod → exact static-container id → bare-stem fallback (when `$id` has no slash and exactly one registered container has that stem). When `$id` resolves to a mod, the mod's `@in` schema is applied to `$args` (defaults filled, required enforced) before the mod's body runs. **Always returns a CTN string.** Never `false`, never throws, never plain `"Error: …"` text:

  | Cause | Returned block |
  |---|---|
  | Schema: missing required input | `CTN: error\ncode: 400\nmessage: Missing required input: <name>\n---\n` |
  | Mod internal throw | `CTN: error\ncode: 500\nmessage: <throwable msg>\n---\n` |
  | Missing id (no mod, no container, no stem) | `CTN: error\ncode: 404\nmessage: container '<id>' not found\n---\n` |
  | Ambiguous stem (>1 match for bare-stem lookup) | `CTN: error\ncode: 500\nmessage: ambiguous stem '<id>' matches multiple containers: …\n---\n` |

  Static containers (exact path-relative id) carry no `@in`; their content is returned verbatim. Callers `echo` the result without type-checking; the acts layer (the html renderer) renders any error blocks inline. (5.0 had two methods, `$C->mod()` and `$C->ctn()`, the former with light intersect-key filtering and the latter loud-throwing on lookup failure. 5.1 collapses both into the contract above.)

- `$C->mod_exists($name)`, `$C->mod_path($name)`, `$C->is_container_mod($name)`, `$C->mod_output_type($name)` — introspection. (Internal storage form retains the "mod" naming; *mod* = a PHP file that produces CTN. The `$C->mod()` method itself is gone; the introspection methods stay.)
- `$C->parse_ctn($string)` — parse CTN text into blocks.

**Properties (read-only)**
- `$C->mods` (name → filepath), `$C->container_mods` (subset of `.ctn.php`), `$C->mod_types` (name → output type), `$C->containers` (path-relative id → filepath), `$C->container_stems` (bare stem → [full id, …]).

## Review criteria (ask before every change)

Five questions. Operationalize the four simplicities (see `mission.md`) as a per-change alignment check. Apply to every mod written, every place edited, every skin tweaked — not just framework edits. Like unit tests at the architectural-alignment level. If one fails without a named reason, the change is drift, not improvement. Reject.

1. **Does this make a part more complex?** One mod, one concern. A 400-line mod doing five things fails.
2. **Does this make an interface less uniform?** `@in` declarations, `CTN: type` naming, `$C->ctn()` call shape — same patterns everywhere. Novel syntax fails.
3. **Does this require a specific realm to test?** Default is realm-agnostic. If a mod can only be exercised from HTTP (or only from CLI), it's realm-locked. Realm-independence is pillar 6 in action.
4. **Does this require changing more than one file to adjust one behavior?** Place file binds args; mod consumes them; skin renders; one behavior = ideally one file. Spreading logic across three files to tweak one thing fails.
5. **Does this degrade the experience on a constrained device?** Old PowerBook G4, slow network, limited JS. Server-render first; no fat client. Reaching for React/Alpine reflexively fails. The G4 test is a drift vaccine, not nostalgia.

## What not to do

Category bans. Complement the review criteria above (criteria ask the general drift questions; bans name specific patterns). Violating requires a named exception. The terse list lives here; full rationale per ban lives in `briefing-detailed.md` §9.

1. **No middleware, hooks, filters, event buses.** A mod is called, produces output, stops.
2. **No shared mutable state between mods.** No `$C` mutation, no globals, no carryover.
3. **No realm globals in mods.** The adapter resolves realm facts and populates `@in`; mods consume inputs only.
4. **No nested CTN blocks.** Blocks are siblings in a flat list.
5. **No explicit `CTN: standard` header on static containers.** Implicit default is the feature.
6. **No component trees, slots, layout engines.** One type = one skin pair.
7. **No Composer, PSR-4, service locators, DI containers, ORMs.**
8. **No abstract base mods or mod-interface hierarchies.** Mods are plain PHP files.
9. **No mod modulates another mod.** A mod may *source* (call) another mod, never *observe* or *override* it.
10. **No mod knows its caller, placement, or realm.** It takes `@in`, returns typed output. Period.
11. **No side effects outside `@in` / output contract.** If a mod's job is a side effect, its CTN output MUST describe what happened. Silent state changes are forbidden.
12. **No `__call` / magic dispatch on Core.** Always `$C->ctn('foo', ...)` explicitly.

## Further reading

- **`mission.md`** — why Containerist exists (origin, principles, imperatives).
- **`briefing-detailed.md`** — full pillar rationale, anti-patterns, drift warnings, worked examples.
- **`CTN-SPEC.md`** — CTN block wire format (authoritative).
- **`IN-SPEC.md`** — `@in` contract grammar.
- **`PLACE-SPEC.md`** — place-file grammar (`@args`, wildcards, per-line args, regions, composition modes, dispatcher variants). 5.0+ canonical surface format.
- **`archive/4.x-stack-format/STACK-SPEC.md`** — *Archived 2026-05-21 (deprecated at 5.0).* 4.x stack-file grammar. Reference material for instance migration from 4.x and for any language port (TS, Go) still at 4.x; see PLACE-SPEC §13 for the conversion table.
- **`ACTS-SPEC.md`** — renderer act dispatch, the seven core acts, adapter-resolved-facts arg-lifecycle extension (session + header facts).
- **`FEDERATION-SPEC.md`** — cross-origin container fetch.
- **`compared-to.md`** — how Containerist differs from Astro, Next.js, Laravel, EmDash, 11ty, etc.
- **Per-module `modules/<ns>/README.md`** (when present) — module-local conventions.
