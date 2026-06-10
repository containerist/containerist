# ACTS — Renderer Act-Dispatch Specification

**Version 1.3 (draft)**
**Status:** Normative. This document is authority for implementations.
**Scope:** Acts are the renderer's block-type dispatch layer. The renderer parses the CTN stream assembled from a place's composed regions, dispatches each block to its matching act (one file, one block type), and builds a **realm-neutral response** (body bytes + an effect-description: status, headers, session-mutations, redirect, flash, head-slots). The realm **adapter** then performs that response. This document defines the dispatch contract, the seven core acts, and the adapter-resolved-facts extension to the arg lifecycle (session-resolved facts at position 4, header-resolved facts as their sibling, and the full six-position lifecycle realization).

**Companion specs.**
- `CTN-SPEC.md` — the wire format blocks flow in.
- `PLACE-SPEC.md` — how a place composes containers into a block stream (5.0+; supersedes the archived `archive/4.x-stack-format/STACK-SPEC.md`).
- `IN-SPEC.md` — the mod-input contract a place populates.
- `containerist.md` — framework briefing; §"Acts and response assembly" is the operator-level summary of this spec.

**Revision trail.**
- *1.0 draft (2026-04-22).* Initial spec. Lands together with: (a) two new core acts — `session` and `flash` — introduced to cover auth without middleware; (b) a third new act — `title` — to populate the page `<title>` element from content-knowing mods (a long-standing Containerist gap: mods emit into the body, the `<head>` was stuck with skin-template defaults); (c) the unification that names **skin** (today implicit) as the default act. Prior to 1.0, Stackers handled control-flow blocks (`redirect`, `error`, `content-type`) inline and invoked the skinner as a separate code path. Acts promote all of it to a single dispatch mechanism, file-per-block-type, with skin as the default when no explicit act matches. Rationale and arc: `logs/2026-04-22-auth-design.md`.
- *1.1 draft (2026-04-29).* Four additive changes since 1.0:
  - **§6.1 Header-resolved facts** — a sibling resolver to session-resolved facts, populating arg-lifecycle position 4 from request headers. Ships one well-known fact name (`bearer`, drawn from `Authorization: Bearer <token>` per RFC 6750). Mods opt in via `@in: bearer (optional)`. Closes the pillar-8 gap for ingest endpoints, webhooks, and machine-to-machine APIs that were reaching for `getallheaders()` inline. Landed in PHP reference at framework 4.4.1.
  - **§6 Lifecycle realization paragraph** — names that all six positions of the precedence list are realized on every render path in conformant 4.4.2+ implementations. Prior to 4.4.2, the PHP reference realized partial subsets per path (stack route had 1–4 only; `.ctn`/`.raw`/`.htmx` had 1+5+6 only). 4.4.2 closes the gap; this paragraph documents what conformance now means.
  - **§6 reserved-name generalization** — replaces PHP-specific `$_GET` wording with language-neutral phrasing. Names `q` (Apache rewrite key) and `trace` (dev-flag) as reserved in PHP/Apache realms; other realms reserve their own. Same change names the position-5-vs-6 collision precedence ("later wins" applies between query and POST too).
  - **§4.4 third-argument clarification** — names that implementations MAY pass an impl-specific context as the third argument (Core handle, path roots, etc.), already done by both PHP and TS reference impls. The two-argument minimum normative contract is unchanged. Lifts the "Third-arg deviation" the TS sidecar self-flagged. Landed in PHP reference at framework 4.4.5 alongside the new `ACTS-in-PHP.md` sidecar — which becomes the canonical home for PHP-specific act conventions previously duplicated across `acts/*.php` file headers.
  - All four changes are strictly additive: §8 conformance clause unchanged; the seven core acts (§5) untouched; existing 1.0-conformant Stackers and mods continue to work unmodified. Rationale and arc: `logs/2026-04-28-bearer-fact-resolver.md` (header facts) + `logs/2026-04-29-arg-lifecycle-completion.md` (lifecycle realization) + `logs/2026-04-30-acts-contract-consolidation.md` (third-arg clarification + PHP sidecar).
- *1.2 draft (2026-05-26).* Two additive changes alongside Containerist framework 5.1:
  - **§5.3 `error` act — Core-emitted blocks.** Clarifies that `CTN: error` blocks may originate from either a mod (intentional control flow) or the Core itself (when an invocation fails — schema 400, missing-id 404, throw 500, ambiguous stem 500; see `containerist.md` §"Public Core API"). The act handles both byte-identically — the source of the block is opaque at the act layer. Already true in practice in pre-5.1 Stackers that caught `InvalidArgumentException` and wrapped it as a `CTN: error` block; 5.1 makes Core-side emission the canonical path and the spec spells out that no act-level distinction exists.
  - **§7.2 CLI Stacker exit-code contract.** Adds a normative exit-code rule for CLI Stackers: process exit `1` when the returned CTN stream begins with `CTN: error`, exit `0` otherwise. The error block itself stays in the typed stdout stream (Pillar 11 — output describes what happened) so downstream consumers can `parse_ctn`, grep, or pipe normally. The exit code gives shell `&&` / `if` chains the binary success signal. Argv parse errors and pre-Core failures (mod-not-found before any invocation) MAY emit to stderr instead, exiting `1`. Lands together with the PHP reference's promotion of CLI dispatch from procedural to a `CliStacker` class at framework 5.1.
  - Both changes are strictly additive: §8 conformance clause unchanged (extended in §10); the seven core acts (§5) untouched in semantics; existing 1.0/1.1-conformant implementations and mods continue to work unmodified. Rationale and arc: `logs/2026-05-26-5-1-back-promotion.md` + `RELEASE-5.1.md`.
- *1.3 draft (2026-05-27).* Re-home alongside framework 5.2 (the CTN-waist hourglass). **No normative change** — the §4 dispatch contract, the seven core acts, and the conformance surfaces are unchanged; only layer attribution moves. Acts now live in the realm-free **renderer** (not "the Stacker," retired) and are **realm-neutral**: they describe effects into the response state, and the **adapter** performs realm I/O (§1.1). §7 realm-notes: "Web/CLI Stacker" → "web/cli adapter". A 1.2-conformant implementation is automatically 1.3-conformant. Arc: `logs/2026-05-27-5-2-ctn-waist-hourglass.md` + `RELEASE-5.2.md`.

---

## 1. What an act is

An **act** is a renderer-layer handler for one CTN block type. It receives a parsed CTN block and a mutable response state, and it records the block's semantics into that state — render to body, *set* a header field, *queue* a session mutation, *queue* a flash. (It describes the effect; it does not perform realm I/O — see §1.1.) One act = one file = one block type.

Mods emit CTN. The renderer collects the emitted CTN in stack order, parses it into a flat block list, and dispatches each block to its act. The act is the only place where the block's type-specific behavior lives.

**One dispatch rule.** Block type `T` is handled by the act file whose filename stem is `T`. If no such file exists, the `skin` act runs (the default: look up `skin/T.{html,css}` and mustache-render). This is the sole mechanism; there is no second code path for "control-flow" versus "content" blocks.

**What acts are not.** Not middleware. An act runs on a single block; it cannot observe or modify another mod's emission. Not hooks, not filters, not an event bus. No subscription model, no priorities, no `before:` / `after:` declarations. Pillar 8 (Core is realm-free) holds because acts live in the renderer — itself realm-free — and Core never invokes an act; the adapter, not the act, touches realm primitives.

### 1.1 Acts are realm-neutral

An act **describes** an effect by writing a field of the realm-neutral response state; the **adapter performs** it. `redirect` sets `response.redirect` (it does not call `header()`); `session` appends to `response.session_mutations` (it does not write the session store); `content-type` sets a `headers` entry; `flash` appends to `response.flash_writes`; `error` sets `response.status`; `skin`/`title` fill `body_blocks`/`head`. The render-vs-effect distinction is *which field of the response a handler fills* — **not** renderer-vs-adapter. This keeps the renderer realm-free: the same act file runs unchanged behind any adapter.

## 2. Design goals

- **One file per act.** A reader who wants to know what `CTN: redirect` does opens `acts/redirect.php` (or `redirect.ts`, etc.) — a ~30–80-line file — and reads it. No indirection, no dispatch-table-to-grep.
- **Symmetry with mods.** Mods are filename-stem-dispatched extensions at the Core layer. Acts are filename-stem-dispatched extensions at the renderer layer. Same pattern, different layer. One mental model covers both.
- **Extensibility without renderer edits.** Adding a new block type is adding a new file. The renderer's main loop does not grow.
- **Skin is the default, not a special case.** The skinner is the first of N acts, not a parallel subsystem. This dissolves the "content vs directive" binary that older drafts maintained, and it gives every content block a declarative escape hatch (drop `acts/article.php`, override render for that type).
- **No configuration surface for act enablement.** Filesystem presence = availability. The renderer discovers acts by scanning its `acts/` directory at boot. Instance can omit or override by file presence. No `enabled_acts:` config key.
- **Realm-agnostic mods, realm-neutral acts, realm-aware adapter.** Mods emit CTN without knowing the renderer exists. Acts don't touch cookies, headers, or session stores either — they describe effects into the response state (§1.1). Only the **adapter** performs realm I/O. Core (engine) and renderer are both realm-free; the adapter is the single realm-aware layer. This three-layer split is pillar 8 operationalized.

## 3. File layout

An act lives at `<renderer-root>/acts/<stem>.<ext>`. The `<stem>` is the block type the act handles. The `<ext>` is the renderer's implementation language (`.php`, `.ts`, `.go`).

Concrete example (PHP, framework 5.2+):

```
core/renderers/
  dispatch.php          # the block-dispatch loop (resolves + runs acts in order)
  acts/
    skin.php            # the default act; renders via skin/<type>.{html,css}
    redirect.php        # CTN: redirect
    error.php           # CTN: error
    content-type.php    # CTN: content-type
    session.php         # CTN: session
    flash.php           # CTN: flash
    title.php           # CTN: title
  html/                 # HTML body assembly (page-wrap; buffered/streaming)
  raw/                  # raw CTN passthrough (.ctn/.raw/.placectn) — skips dispatch
  source/               # .place source dump
```

The acts are realm-free; only the surrounding adapter (`core/adapters/web/`, `core/adapters/cli/`) performs realm I/O. Language-specific file shape, discovery mechanism, and act-function signature live in `ACTS-in-<Lang>.md` sidecars. This spec is language-neutral at the contract level.

## 4. Dispatch contract (normative)

### 4.1 Block parse

The Core composes the place: it invokes every mod and static container in the stack's body order and concatenates their emitted text into a single CTN stream. The renderer parses that stream per `CTN-SPEC.md` §4 and obtains an ordered block list.

### 4.2 Act resolution

For each block with type `T`:

1. If a file `acts/T.<ext>` exists, it is the **matching act**.
2. Otherwise, the matching act is `acts/skin.<ext>` (the default).

Exact byte-match on the stem; no case folding, no alias map. The block type `T` is the string produced by CTN-SPEC §4.2.1 (trimmed text after `CTN:` on the header line).

A block with empty type (`CTN:` with nothing after) matches no act and produces a rendering warning. Conformant renderers MUST NOT crash on empty-type blocks; they SHOULD log and continue.

### 4.3 Execution order

Acts execute **in block order** — the order the blocks appear in the assembled stream, which is the order their emitting mods appear in the stack body.

There are no phases, no priorities, no pre/post hooks. If a mod emits `CTN: session` followed by `CTN: redirect`, the session act runs before the redirect act because the session block appears before the redirect block. The mod author controls ordering by emission order. This is visible in the mod source.

### 4.4 Response state

Each act receives two normative arguments: the parsed block, and a mutable **response state**.

**Implementations MAY pass a third argument or implementation-specific context** carrying realm/impl access not expressible at the spec level — for instance, a Core handle for acts that invoke mods to render content (the `skin` and `error` acts in the reference impls) or root-path information for resolving on-disk skin assets. The minimum normative contract remains: parsed block + mutable response state. Acts that don't need the third argument accept it and ignore it; portability between impls is preserved as long as an act's body restricts itself to the spec's two normative arguments. Specific shapes per impl in `ACTS-in-<Lang>.md`.

The response state carries everything the adapter needs to perform the response after all acts have run — it is the realm-neutral handoff from renderer to adapter. Its shape (normative minimum):

```
ResponseState {
  status:             integer       (default: 200)
  headers:            map<string, string>
  body_blocks:        ordered list of rendered-HTML fragments
  head:               map<string, any>          (page-shell slots: title, meta, etc.)
  session_mutations:  ordered list of (set|clear, key, value) operations
  flash_writes:       ordered list of (key, value) entries for next-request injection
  redirect:           { to: string, status: integer } | null
  short_circuit:      boolean       (default: false)
}
```

Acts mutate this state — they populate a realm-neutral *description*; realm I/O (writing the session store, setting headers, emitting status, sending the body) happens at the **adapter**, never in an act. After all acts run, the **renderer** finalizes the response (composing the page body: the page-shell template receives `head` and `body_blocks`, producing body bytes) and hands the complete state to the **adapter**, which performs it:

1. Apply `session_mutations` to the session store in order.
2. Apply `flash_writes` to the session store's flash slot.
3. If `redirect` is set, emit status 302 (or the block-supplied status), `Location:` header, and an empty body. Done.
4. Otherwise emit `status`, `headers`, and the renderer's composed HTML body.

The `head` slot is a flat map keyed by element name. Each head-slot act (`title` in 1.0; `meta-description`, `canonical`, `og-*` etc. as future additions) writes its own key. Value shape is element-specific — `title` writes a string; future acts may write strings, lists, or small mappings. Keys SHOULD be short slugs (`title`, `description`, `canonical`, `og_image`).

Language-specific response-state types (class, struct, interface) live in `ACTS-in-<Lang>.md`. The semantic minimum above is what all implementations must carry.

### 4.5 Short-circuit

An act MAY set `response.short_circuit = true` to signal that subsequent acts should not run. The renderer checks this flag after each act returns; if set, it stops iterating the block list and finalizes the response, which the adapter then performs (§4.4).

**The redirect act sets short_circuit on first call.** A mod that emits `CTN: redirect` as its fourth of six blocks causes blocks 5 and 6 to be skipped. Blocks 1–3 have already executed (including any `CTN: session` mutations); those effects stand. Short-circuit is eager, not lazy.

Short-circuit does NOT apply retroactively to already-executed acts. `session_mutations` queued before the redirect are still applied in §4.4 step 1. This is deliberate: a login flow emits `CTN: session\nset: user_id = 42` followed by `CTN: redirect\nto: /`. The session must be written before the redirect is sent.

## 5. The seven core acts (normative)

A conformant renderer SHALL ship these seven acts with the semantics defined below.

### 5.1 `skin` — the default act

Block type: any type with no explicit act file.

**Behavior.** Look up `skin/<type>.html` and `skin/<type>.css`. Parse the HTML as a Mustache template. Render with the block's `fields` (HTML-escaped) and `body` (raw, available as `{{{body}}}`). Append the rendered HTML to `response.body_blocks`. The renderer is responsible for ensuring `skin/<type>.css` is included in the page's stylesheet stream (implementation-specific; see `ACTS-in-<Lang>.md`).

If the skin pair is missing for a block type with no matching act, the renderer logs a warning and appends nothing. The request does not fail.

**Why it's an act.** Unifying skin-render into the acts layer means the dispatch rule is total: every block goes through acts. It also means a block type can be taken out of the default render path by dropping in its own act file. This is an opt-in escape hatch; most block types never use it.

### 5.2 `redirect` — rewrite the response location

Block shape:

```
CTN: redirect
to:     /target/path        (required)
status: 302                  (optional, default 302; 301, 303, 307, 308 permitted)
---
```

**Behavior.** Set `response.redirect = {to, status}`. Set `response.short_circuit = true`. The adapter emits the redirect at response-assembly time (§4.4 step 3) — no body is sent. (The act only sets the field; it does not call `header()`.)

Side effects earlier in the block stream (e.g. queued session mutations) are preserved and applied before the redirect is emitted.

### 5.3 `error` — change HTTP status and render error content

Block shape:

```
CTN: error
code: 400                   (required; integer in the 4xx or 5xx range)
---
Error message text (optional, rendered via the error skin if present)
```

**Behavior.** Set `response.status = code`. If `skin/error.html` exists, render the block through it and append to `body_blocks`. Do NOT set `short_circuit` — subsequent blocks may still render (e.g. a page that shows a form with an error banner above it).

An error act does not redirect. To redirect on error, emit both `CTN: error` and `CTN: redirect` in the mod's output. The combined semantics: status is set from `error`; redirect short-circuits; target page receives the error status. (In practice, a redirect response's status is 3xx regardless of a prior `error` block. Conformant implementations MUST let the redirect's status win when both are present.)

**Source-opaque dispatch (1.2+).** A `CTN: error` block may be emitted by a mod (intentional control flow — validation failure, business-rule rejection) or by the Core itself when an invocation fails (per `containerist.md` §"Public Core API": schema 400, throw 500, missing-id 404, ambiguous-stem 500). The act handles both byte-identically; the block's origin is opaque at the act layer. This means no try/catch is needed at external call sites — a mod that calls `$C->ctn('other', $args)` and receives an error block simply echoes it, and the resulting position in the assembled stream is rendered by this act normally.

### 5.4 `content-type` — change the response MIME type

Block shape:

```
CTN: content-type
value: application/rss+xml   (required)
---
```

**Behavior.** Set `response.headers["Content-Type"] = value`. Does not short-circuit. Typical use: a mod emits a `CTN: content-type` block followed by a single-block body containing the non-HTML payload (RSS XML, JSON, plain text). The downstream skin act for the payload type renders without HTML-wrapping.

### 5.5 `session` — mutate the session store

Block shape (two forms):

```
CTN: session
set: user_id = 42            (one or more set: lines; see below)
---
```

```
CTN: session
clear: true                  (clears the entire session store)
---
```

**Set form.** Each `set:` field is a `key = value` pair. Multiple `set:` entries MAY appear; each is queued as a separate `session_mutation` in order. Keys are strings. Values are YAML scalars per CTN-SPEC §8.2 (string, int, float, bool, null).

Authors who want to set multiple keys in one block use a YAML mapping:

```
CTN: session
set:
  user_id: 42
  role: admin
---
```

Both forms (repeated `set:` lines and a single `set:` mapping) are accepted; the latter is idiomatic for multi-key writes.

**Clear form.** The field `clear: true` queues a full session-store clear. Partial-key clear (`clear: [user_id, role]`) is permitted; conformant implementations MUST support list-of-keys semantics when `clear` is a sequence.

**Behavior.** Append the mutation(s) to `response.session_mutations`. Do not short-circuit. Typical pairing: `CTN: session` + `CTN: redirect` (login sets the session and sends the user to the home page).

The `session` act only queues the mutation onto `response.session_mutations`; the **adapter** writes the session store at response-assembly time (§4.4 step 1). Session storage is therefore an adapter (realm-I/O) concern — this spec does not dictate cookie format, server-side store, or session-ID scheme. See `ACTS-in-<Lang>.md` for the adapter's session backend.

### 5.6 `flash` — write a one-shot message to the next request

Block shape:

```
CTN: flash
key: welcome                  (required; the arg name injected on next request)
msg: "Welcome back, Konstantin"  (required; the scalar or mapping to store)
---
```

**Behavior.** Append `{key, msg}` to `response.flash_writes`. At response-assembly time (§4.4 step 2), the adapter writes these entries into the session store's flash slot. On the **next** request from the same session, the adapter injects each flash entry as a candidate `@in` arg and clears it after read.

A mod on the next request declares `@in: flash_welcome (optional)` (or whatever key was used) and renders the message if present. Empty / absent = no message shown.

**One-shot.** Flash entries are removed from the store after injection. A second request sees nothing. Refresh-safe: reloading the target page after the flash has been consumed does not re-show the message.

**CLI realm.** A mod emitting `CTN: flash` under the cli adapter writes to nowhere — CLI has no session continuity. Conformant cli adapters SHOULD log a warning and continue. This is not an error.

### 5.7 `title` — populate the page `<title>` element

Block shape:

```
CTN: title
value: "My Blog Post"        (required; the string placed in <title>)
---
```

**Behavior.** Set `response.head["title"] = value`. Does not short-circuit.

**Last-write-wins by emission order.** A stack where a header mod emits `CTN: title value: "Konnexus"` (default) and a later detail mod emits `CTN: title value: "My Post"` (specific) resolves to `"My Post"` — the specific mod appears later in the stack, its title supersedes the default. This matches the general emission-order semantics of §4.3: later blocks in the stream win on key collision in `head`.

**Stacks where no mod emits `CTN: title` leave `response.head["title"]` absent.** The page-shell skin decides the fallback (see below). Behavior is unchanged for sites that never emit a title block — the renderer does not inject a default.

**Skin-layer plumbing.** The page-shell skin template reads `{{head.title}}` when composing the `<head>` element:

```html
<title>{{head.title}}</title>
```

Sites that want a fallback supply it via Mustache's inverted-section syntax:

```html
<title>{{#head.title}}{{head.title}}{{/head.title}}{{^head.title}}Konnexus{{/head.title}}</title>
```

The fallback lives in the skin, not in the act. Sites that want no fallback render an empty `<title>` when no mod emits one.

**A mod provides content for multiple head elements by emitting multiple blocks.** One mod may emit `CTN: title` plus future `CTN: meta-description` and `CTN: og-image` blocks in one invocation — the renderer treats them as independent head writes, each landing in its own `response.head` key. Cross-element coordination is the mod author's concern; the acts layer only enacts each declaration.

**Reserved head-slot keys.** `response.head` is a flat map keyed by short slugs. The key space is reserved by this spec — adding a new head-slot act means adding a row here first, then the act. Acts MUST NOT write to a key not listed here; sites MUST NOT rely on a key marked `future` being populated; a future revision promoting a key from `future` to `landed` does not change the key's name or value shape.

| Key | Block type | Value shape | Status | Notes |
| --- | --- | --- | --- | --- |
| `title` | `CTN: title` | string | **landed (1.0)** | Page `<title>` element. Fallback in skin. |
| `description` | `CTN: meta-description` | string | future | `<meta name="description">`. |
| `canonical` | `CTN: canonical` | string (URL) | future | `<link rel="canonical">`. |
| `og_title` | `CTN: og-title` | string | future | `<meta property="og:title">`. Underscore, not hyphen — YAML-safe. |
| `og_description` | `CTN: og-description` | string | future | `<meta property="og:description">`. |
| `og_image` | `CTN: og-image` | string (URL) | future | `<meta property="og:image">`. |
| `og_type` | `CTN: og-type` | string | future | `<meta property="og:type">`. Default usually `website`. |
| `robots` | `CTN: meta-robots` | string | future | `<meta name="robots">`. Typical values: `index,follow`, `noindex`. |
| `alternate` | `CTN: link-alternate` | list of `{rel, href, type?}` | future | `<link rel="alternate">` for feeds / locale variants. First list-valued key. |

Each future slot is a small act file (~15 lines); the pattern is set by `title`. Add the row when landing the act, not before — unreferenced rows rot. A future key that doesn't fit (e.g., JSON-LD script blocks) is a spec revision, not a silent addition.

**Contract on the key space.**
- Keys are short slugs: lowercase ASCII, underscores for word separation (`og_title`, not `og-title` — the latter is the block type; YAML-safe under Spyc's core schema).
- Value shape is frozen once a key is `landed`; adding optional sub-fields to a mapping-valued key is a minor addition, changing a string to a mapping is not.
- Sites MAY register instance-local head keys under a prefix reserved for them (`x_` by convention) without requiring a spec revision. Framework-level keys (no prefix) come from this table only.

## 6. Session facts in the arg lifecycle (extension)

Acts alone do not give a mod access to "who is calling?" That fact lives in the session store, which the **adapter** resolves **before** any mod runs (realm-fact resolution is the adapter's inbound job). To make session-resolved facts available to mods via the existing `@in` contract, the arg lifecycle gains one source.

Updated precedence (later wins on key collision):

1. URL numeric parts (`{{1}}`, `{{2}}`, …)
2. Stack `@args`
3. Stack per-line args
4. **Adapter-resolved facts** (session-resolved + header-resolved; injected by the adapter at request start) ← added in 1.0; header-resolved sibling added at 4.4.1
5. Query string keys (excluding framework-reserved names; PHP/Apache reserves `q` for the rewrite key and `trace` for the dev-mode debug flag — other realms reserve their own)
6. POST body keys

Within the lifecycle, **later positions override earlier ones on key collision** — a POST field named `term` overrides a same-named query value, which overrides a same-named position-4 fact, which overrides a stack-bound `@args` `term`. Same rule across all positions; no special cases.

The new source sits between stack-level args and query string. Rationale: stack-level args are authored bindings (highest trust); session facts are adapter-resolved bindings (next); query string is user-supplied (lowest, but can override for tests).

**What the adapter injects.** At minimum: a `user` key, bound to the session store's `user_id` value (or a richer record if the site's session backend carries one). An unauthenticated request has `user = null`. Plus any flash entries written on the previous request, injected under their chosen `key` name and cleared after injection (§5.6).

**Arg lifecycle's schema check unchanged.** Mods that don't declare `@in: user (optional)` don't see `user`. Adding a new adapter-injected fact does not retroactively affect any mod; mods opt in by declaring.

**Login / logout semantics.** A login mod validates credentials, emits `CTN: session\nset: user_id = N` + `CTN: redirect\nto: /`. The adapter applies the session mutation in §4.4 step 1 (writing the cookie or server-side entry), then sends the redirect. The next request starts with `user_id` resolved from the cookie; the adapter injects it as `@in: user` per this section. No mod ever touches a session API.

**Lifecycle realization.** All six positions in the precedence list above are realized on every render path in conformant implementations starting at framework 4.4.2 (PHP reference). Prior to 4.4.2, the PHP reference realized only partial subsets per path:

- Stack-routed (no-suffix) URLs realized positions 1–4 (URL parts + stack @args + per-line + adapter-resolved facts), but skipped 5 (query) and 6 (POST). Form-POST and query-string args were invisible to `@in`-declared mods reached via stacks.
- URL-suffix dispatch paths (`.ctn`, `.raw`, `.htmx`) realized positions 1, 5, 6 (URL parts + query + POST), but skipped 4 (adapter-resolved facts). Mods reached via these paths could not see session-resolved `user` or header-resolved `bearer` via `@in`.
- The inspection path `.stackctn` realized only positions 1, diverging from production rendering for any auth-gated or POST-bodied page.

4.4.2 closes all three gaps. Both render paths now wire the full lifecycle, and `.stackctn` mirrors the production composition so inspection is faithful. Strictly additive: mods that don't declare names matching the new positions see no behavior change.

### 6.1 Header-resolved facts (extension)

Position 4 admits more than one resolver. Where session-resolved facts (§6) read the cookie-bound session store, **header-resolved facts** read the request headers — for credentials that arrive per-request rather than as session state.

The reference now ships a `bearer` fact: when the request carries `Authorization: Bearer <token>` (RFC 6750), the adapter injects `bearer = <token>` into position 4 alongside session facts. Mods opt in by declaring `@in: bearer (optional)`. Empty array if absent or if the Authorization scheme is not Bearer.

**Why not session-only.** Bearer tokens identify a request, not a user-agent across requests; storing them in the session would invert the trust direction (session lookup of a per-request fact). Header-resolved facts let machine-to-machine endpoints (ingest APIs, webhooks, tooling) declare auth via `@in` without reaching for `getallheaders()`, preserving pillar 8.

**Precedence.** Header facts merge AFTER session facts at the position-4 merge point. A request-bound credential overrides a same-named session value (the request is fresher than stored state). The collision is theoretical — `bearer` is not a name session.php produces — but the rule is documented for future header-resolved names.

**Sibling, not new mechanism.** Position 4 is unchanged. A site with both resolvers active sees one merged dictionary at that position, the same shape that previously held only session facts. Adding bearer is a sibling fact source, not a new mechanism class.

**Conformance.** Header-resolved facts are OPTIONAL. The §8 conformance clause is unchanged: an implementation is conformant if it injects session-resolved facts per §6. Implementations MAY also ship header-resolved facts (the PHP reference does, since framework 4.4.1); mods that don't declare `@in: bearer` see no change.

**Future header-resolved names.** API keys (`X-API-Key`), mTLS subject DNs, SSO assertion claims, and request-id headers are plausible future fact names. Each lands behind the same opt-in rule (mod declares `@in: <name>`); the implementation registers the resolver in `core/lib/containerist/auth_header.php` (or a sibling). When the count of resolvers reaches "many", the mechanism gets generalized to a registry — that would be the moment for an ACTS version bump.

## 7. Realm notes

### 7.1 Web adapter

The web adapter drives the html renderer's full acts dispatch per §4; all core acts are active. Session backend is implementation-specific (an adapter concern); see `ACTS-in-<Lang>.md`.

**Auto-PRG on place URLs (5.0+).** A POST request to a place URL (any URL that resolves to a `.place` file, per `PLACE-SPEC.md` §4) is **auto-PRG'd** by the web adapter (it is realm I/O — receiving the POST, stashing, redirecting — so it lives at the adapter, not in any act):

1. The adapter captures the incoming POST body and stashes it in the session store under a per-place key (e.g. `_prg_pending[<place_name>]`).
2. The adapter emits a 303 See Other response with `Location:` set to the same URL stripped of query string. No mod runs on this request.
3. The browser follows the 303 as a GET.
4. The adapter, on the GET, consumes the pending POST stash and merges it into arg-lifecycle position 6 (POST body) for that one request. From the mod's perspective, this looks identical to a direct POST: `@in: <name>` sees the same values.

**Why.** Direct POST-to-place dispatch in 4.x left every POST-bearing surface vulnerable to browser refresh-resubmits, the URL line carrying transient form state, and double-submits on slow connections. Auto-PRG converts every POST into the canonical safe pattern (POST → 303 → GET) without each place author having to wire it manually.

**HTMX requests bypass auto-PRG.** A POST carrying the `HX-Request: true` header (HTMX's marker) is dispatched directly — HTMX expects a response body, not a redirect, and uses its own fragment-swap semantics. Suffix-dispatch paths (`.htmx`, `.ctn`, `.raw`) are mod-routes, not place-routes, and also bypass auto-PRG by construction.

**Bearer-authed requests bypass auto-PRG.** A POST carrying an `Authorization: Bearer …` header (RFC 6750; the scheme that populates the `bearer` fact per §6.1) is dispatched directly. The reasoning mirrors the HTMX bypass: machine-to-machine API clients send credentials per-request and expect a synchronous response body, not a redirect they would have to follow with cookie state they don't carry. The bypass is automatic — no per-place config needed for the typical M2M case.

**Per-place opt-out via `prg: off`.** A place file MAY declare `prg: off` in its frontmatter (`PLACE-SPEC.md` §6.5) to bypass auto-PRG explicitly for all POSTs to that place, including session-authed POSTs that wouldn't be caught by the HTMX or Bearer bypasses. Intended for JSON / multipart APIs whose clients are not browsers but also don't use Bearer auth. The two automatic bypasses solve the common cases; `prg: off` is the explicit-override for everything else.

**Effect on the arg lifecycle.** Position 6 (POST body) is reached *after* the PRG round trip, not on the initial POST. The "later wins" precedence (§6) is unchanged. Implementers and smoke writers should drive PRG flows as two curl invocations sharing a cookie jar (a single `curl -L -X POST` re-POSTs on every redirect hop because curl preserves the explicit method across redirects).

Conformance for ACTS-SPEC does NOT require auto-PRG; it is a web-adapter policy with no impact on the act-dispatch contract. The PHP reference enables it as the 5.0 default; alternative web adapters MAY opt out, OR add a per-place `prg:` frontmatter key (not currently in PLACE-SPEC) to override.

### 7.2 CLI adapter

The cli adapter uses the raw renderer by default (CTN → stdout) and MAY drive a subset of acts. Recommended behavior:

- `skin` — active if the mod's output is intended for human display; bypassed if the CLI is returning raw CTN.
- `redirect` — active if the CLI is simulating a web request (test harness). Otherwise logged and ignored.
- `error` — sets the CLI exit code per the exit-code contract below.
- `content-type` — logged and ignored (CLI has no HTTP response).
- `session` — logged and ignored unless the CLI has a file-backed session (optional feature).
- `flash` — logged and ignored.

Conformance does not require cli adapters to implement session/flash. It does require cli adapters to NOT crash on these blocks — logged-and-continue is the baseline.

**Exit-code contract (1.2+).** A conformant cli adapter returns process exit code `1` when the returned CTN stream begins with `CTN: error` (regardless of whether the block was emitted by a mod or by the Core; see §5.3 source-opaque dispatch). Exit code `0` otherwise. The error block itself remains in the typed stdout stream — errors are typed output (Pillar 11: output describes what happened) and downstream consumers can `parse_ctn`, grep, or pipe them like any other CTN. The exit code gives shell `&&` / `if` chains the binary success signal.

Argv parse errors, usage messages, and pre-Core failures (mod-not-found before any invocation is attempted) MAY emit to stderr (CLI-realm affordance for usage mistakes) and MUST exit `1`. Once the Core call has been made, the cli adapter MUST emit the returned CTN to stdout verbatim — including any `CTN: error` blocks — and derive the exit code from the leading block type. The contract is leading-block-type-driven, not block-count-driven: a stream that begins with a `CTN: standard` block but contains a later `CTN: error` block exits `0`, because the leading block determines the call's overall success status. Mods that want a failure exit code arrange for the error block to be the first block emitted.

### 7.3 Other realms

A future mcp adapter, sse adapter, or similar drives the renderer's acts as appropriate to the realm. The contract in §4 is portable; the core-act set in §5 is the shared vocabulary every realm must parse and either enact or ignore gracefully.

## 8. Conformance

An implementation is **conformant** with ACTS 1.0 if and only if:

1. It parses CTN per CTN-SPEC.md §4.
2. Its renderer implements the dispatch contract of §4 (resolution, execution order, response state, short-circuit).
3. Its renderer ships all seven core acts of §5 with the semantics defined.
4. Its adapter injects session-resolved facts into the arg lifecycle per §6.
5. It handles unknown block types by falling through to the `skin` act per §4.2.
6. It does not crash on empty-type blocks (§4.2) or on acts emitted in a realm that cannot enact them (§7).

A **conformance test site** lives at `conformance/acts/`. It exercises each act in isolation and in combination (login → gated page → logout → flash). Each test case is a pair:

- `<case>.request.txt` — the incoming URL, method, headers, and body.
- `<case>.response.txt` — the expected status, headers (relevant subset), and body.

Conformant implementations MUST produce byte-equivalent responses (modulo adapter-specific headers like `Date`, `Server`) for every case.

## 9. Known limitations

Deliberate simplifications. Flagged as candidates for a future version.

- **No `remember-me` act.** Long-lived session cookies are an adapter-local concern. A dedicated `CTN: remember` block is plausible if multiple sites want the same shape.
- **No structured flash beyond scalar/mapping.** Flash values are arbitrary YAML scalars or mappings. A rich flash type system (severity levels, dismissable flags, i18n keys) is deferred.
- **No CSRF-token act.** CSRF protection is an adapter-level concern today (enforced in the realm layer, not in mods). Promoting it to an act is plausible once cross-impl demand surfaces.
- **No `cache-control` act.** Pattern matches `content-type` shape; lands when concrete pressure surfaces.
- **No `files` resolver.** Multipart upload bodies (`$_FILES`-shaped data) are not yet a position-4 fact. Mods reading uploads do so inline as a documented exception. Plausible sibling to `bearer` once a third site needs it.
- **No act composition.** Acts cannot call other acts. If an act needs behavior another act provides, it duplicates it or calls into shared lib code. This keeps dispatch flat; composition is a future consideration only if a real need surfaces.

## 10. Versioning

This is ACTS **version 1.3** (draft). See the revision trail at the top of this document for what landed at each version.

**Bump rules.**

- **Major bump** — breaking change to dispatch contract (§4) or a core act's semantics (§5). Renaming or removing a core act is a major bump.
- **Minor bump** — additive feature: a new mechanism category at the §6 lifecycle level (sibling resolvers, new well-known fact names, new conformance dimensions), spec-text expansion that prior readers wouldn't have seen, new core acts.
- **No bump** — pure clarification, language-neutralization, conformance-fixture additions that don't change normative meaning, impl-only spec-compliance fixes (a documented behavior the impl now realizes correctly), **layer-relabel that leaves the dispatch contract and act semantics intact** (the 1.2 → 1.3 re-home).

**Test for whether to bump.** If a reader of this spec at the prior version's draft date wouldn't see your addition by reading their copy, bump. Silent spec mutation under stable version labels is bad practice.

**1.0 → 1.1** is a minor bump because §6.1 introduces a new mechanism class (header-resolved facts as sibling resolvers — not a new act, but a new conformance surface). The §6 lifecycle-realization paragraph and reserved-name generalization ride alongside as clarifications; either alone wouldn't have warranted the bump.

**1.1 → 1.2** is a minor bump because §7.2 introduces a new normative conformance surface (CLI exit-code contract) where 1.1 only had recommended behavior. The §5.3 source-opaque-dispatch clarification rides alongside; on its own it would have been a no-bump revision since 1.0/1.1-conformant `error` acts already handled both block sources correctly.

**1.2 → 1.3** (this revision) is a **clarification + layer-relabel**, not a normative change: acts re-homed under "the renderer," realm I/O attributed to "the adapter," realm-notes reframed (Web/CLI Stacker → web/cli adapter). The dispatch contract (§4), the seven core acts (§5), and all conformance surfaces (§6–§8) are unchanged in meaning. The version label moves to 1.3 so a reader can see the re-home happened (per the bump test — the prose a 1.2 reader saw is now different), but a 1.2-conformant implementation is automatically 1.3-conformant; nothing new is required.

Conformance to ACTS 1.0 remains valid: a 1.0-conformant implementation that implements §4 dispatch + §5's seven core acts + §6 session-resolved facts continues to satisfy §8. 1.1 conformance additionally requires lifecycle realization on every render path (positions 1–4 minimum on stack-routed; same set on URL-suffix dispatch) and MAY ship the §6.1 header-resolved-facts resolver. **1.2 conformance** additionally requires the cli adapter to follow the §7.2 exit-code contract (exit `1` when leading block is `CTN: error`, `0` otherwise); the §5.3 source-opaque clause is clarification only. **1.3 adds no new conformance requirement.**

---

*Acts are Containerist's answer to "how does the realm layer extend without growing a middleware system." One file per block type, flat dispatch, no magic. When in doubt, this spec is authority.*
