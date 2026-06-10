# PLACE-SPEC — Place Declaration Specification

**Version 1.1**
**Status:** Normative. This document is authority for implementations.
**Scope:** A place file declares the structure of a Containerist surface — a URL-resolvable composition of named regions, each with its own containers and composition rules. Place files are the canonical surface-declaration format as of Containerist 5.0; they replace the legacy `.txt` stack format described in `archive/4.x-stack-format/STACK-SPEC.md` (deprecated at 5.0). The implementation resolves an incoming URL to a place file, composes its regions, and renders the result through the acts dispatch and skin pairs.

**Dependencies:**
- `CTN-SPEC.md` — every place file is a CTN document whose first block has `type = "place"`. CTN-block syntax (header + frontmatter + `---` + body) frames the wire form.
- `IN-SPEC.md` — mods invoked from a place honor the same `@in` contract.
- `ACTS-SPEC.md` — the CTN stream produced by composing a place dispatches through the acts layer (`skin`, `redirect`, `error`, `content-type`, `session`, `flash`, `title`, …).
- `FEDERATION-SPEC.md` — `@federation` frontmatter and `http(s)://…` container refs follow the federation contract.

**Prior thinking:**
- `containerist-for-the-ai-age_v0-2_260511.txt` — the declaration of intent that motivated the original (0.1) place format.
- `formbarkeit-des-ui_K260416A.txt` — source of *intentionstreue*, trust-without-consistency, memory-as-design-variable. The place format is the structural answer to the design problems identified in that essay.

**Revision trail.**
- **1.1 (2026-05-21).** Additive: new optional `prg:` key in the frontmatter (§6.5). Values: `auto` (default — Web Stacker applies its own PRG policy per `ACTS-SPEC.md` §7.1) or `off` (explicit opt-out — the Stacker MUST dispatch POSTs directly without redirecting). Surfaced by step 6 of the 5.0 propagation when pond-mk4's place-routed POST APIs (`/ingest`, `/pond-drop-edit`, `/pond-upload-submit`) regressed under unconditional auto-PRG — they're JSON APIs designed to return responses synchronously, not 303-redirects. Companion change in `ACTS-SPEC.md` §7.1: Web Stacker auto-PRG also bypasses on the `Authorization: Bearer …` request header (mirrors the existing HTMX-Request bypass). The two bypasses solve different cases: bearer bypass auto-handles M2M API endpoints with zero per-place config; the `prg:` key gives place authors explicit control for session-auth JSON APIs and other non-browser write surfaces. Strictly additive — places without the key see no behavior change.
- **1.0 (2026-05-21).** Promotion to normative. Three coupled changes land together as the Containerist 5.0 generational cut:
  - **Place subsumes stack as the canonical surface format.** A place file with a single `fixed` region is the direct equivalent of a 4.x `.txt` stack; fluid regions are net-new capability not expressible in `.txt`. The 4.x stack-format spec (`archive/4.x-stack-format/STACK-SPEC.md`) is archived; `.txt` stack support is removed at 5.0 in the reference implementation.
  - **CTN-headed YAML form codified.** A place file is a CTN document whose first block has `type = "place"`. The block's **CTN frontmatter** (everything between the `CTN: place` header and end-of-file) carries the entire YAML declaration; there is no `---` separator and no body section. This unifies the parser path (same `parse_ctn()` as stacks and any other CTN doc) and resolves the ambiguity left in 0.x ("file is YAML" vs "file is CTN block"); 1.0 says both — it's a CTN block whose declaration lives in CTN frontmatter. See §3.1 for the canonical shape and the rationale.
  - **`.place` extension canonicalized.** 0.1 specified `.yaml`; 1.0 specifies `.place`. The validating implementation (konnexus-ai) settled on `.place`, and the dedicated extension cleanly separates surface declarations from any other YAML files the instance may carry. Migration is a one-shot rename.
  - URL resolution semantics (parts extraction, wildcard priority, suffix classification), `@args` binding grammar, and `@federation` frontmatter — all lifted from `archive/4.x-stack-format/STACK-SPEC.md` §4 + §6 with adjustments for the place form. Where 0.1 said "place files do not participate in CTN block dispatch", 1.0 reverses: a composed place produces a CTN stream that goes through acts exactly like a composed stack used to.
  - New: `dispatcher:` frontmatter key selects buffered (default) or streaming dispatch semantics for the place. See §9.
  - New: regions support `candidates:` (LLM accept-list, distinct from `containers:` always-included) as a refinement of 0.1's monolithic `containers:` field for fluid modes. See §7.
- **0.2 — 2026-05-12** (as `PLACE-SPEC`). Added §3 "File layout and URL routing". Place files in `stacks/` with `.place` extension, STACK-SPEC §3-grammar filenames. `@args:` field for named URL-wildcard bindings.
- **0.1.** Initial working draft of the place-file surface declaration format. AI-realm scoped, pure-YAML form, regions as top-level keys, modes `fixed` / `constrained-fluid` / `open-fluid`.

---

## 1. What a place file is

A place file is a CTN document of type `place`. Its first (and authoritative) CTN block carries the surface declaration in its **CTN frontmatter** — a single YAML mapping containing every place-level key: routing directives (`@args`, `@federation`), per-place configuration (`PLACE`, `purpose`, `dispatcher`, `auth`), and either a flat `containers:` sequence (simple form) or a `regions:` mapping (multi-region form). The block's body section is empty by convention; the entire post-header content is one YAML parse.

The implementation reads a place file when it resolves an incoming URL and needs to know what content to produce. It:

1. Resolves the URL to a place file via priority-sorted wildcard matching (§4).
2. Parses the place file as a CTN document; takes the first block.
3. Resolves the frontmatter directives (`@args`, `@federation`) against URL parts.
4. Reads per-place keys and regions from the same parsed frontmatter mapping.
5. Composes containers per region (per the region's mode and the selected dispatcher), assembling their CTN output.
6. Dispatches the combined CTN stream through the acts layer.

The fixed-region-only form of a place file is the direct equivalent of a 4.x `.txt` stack. A place with one fixed region containing `[page-header, article, page-footer]` produces the same CTN stream as a `CTN: stack`-headed `.txt` file listing the same three names. Fluid regions (`constrained-fluid`, `open-fluid`) extend the format to LLM-composed surfaces that `.txt` stacks cannot express.

## 2. Design goals

- **CTN-native.** The file is a CTN document. The same parser used for any CTN content reads place files. The CTN frontmatter is pure YAML; any YAML parser, linter, or schema validator works on it without Containerist-specific tooling.
- **Declarative.** A place declares structure and rules. It does not contain logic, conditionals, or computed values.
- **Self-describing.** The `purpose` fields — both top-level and per-region — are written for an LLM to read. They are the primary input to intent-based composition in fluid regions.
- **Flat at the region level.** Regions are siblings, not nested. A place is a flat set of named regions, not a tree.
- **URL-scoped.** URL parts simultaneously select the place and bind as named arguments — two effects from one resolver pass.
- **Deterministic matching.** Wildcard priority is fully defined. No ambiguity between two places that could both match the same URL.
- **Realm-portable.** Place files declare surface structure; the realm adapter (web, mcp, …) reads them. The format itself is realm-neutral.
- **Language-neutral references.** Substitution grammar (`{{…}}`) reads the same to a PHP developer, a Go developer, an information architect, and an LLM.

## 3. File layout

Place files live under an implementation-configured root (by convention `places/`). Each file has a `.place` extension.

The filename (without `.place`) is the **place pattern**: a sequence of URL-part-matchers joined by `--`. Each matcher is either an exact string or the single-character wildcard `*`.

Examples:

| Filename            | Matches URL paths          |
| ------------------- | -------------------------- |
| `index.place`       | `/` (empty path)           |
| `feed.place`        | `/feed`                    |
| `n--*.place`        | `/n/<anything>`            |
| `archive.place`     | `/archive`                 |
| `archive--*.place`  | `/archive/<year>`          |
| `archive--*--*.place` | `/archive/<year>/<month>` |
| `*--*.place`        | any 2-part URL             |

### 3.1 CTN-block form

A place file is a CTN document. Per CTN-SPEC §4, it MAY be:

- **Implicit-form** (no `CTN:` header): NOT valid as a place file. The adapter MUST reject (404 / error, implementation's choice).
- **Explicit-form**: the first block MUST have `type = "place"`. Subsequent blocks, if any, are ignored.

A conformant place file's first block has:

- `type = "place"` (required)
- `fields` — a YAML mapping (the CTN frontmatter) containing all place-level keys: `@args`, `@federation`, `PLACE`, `purpose`, `dispatcher`, `auth`, `containers`, `regions`. See §5 (the URL-resolved directives `@args` / `@federation`) and §6 (the surface-structure and per-place-configuration keys).
- `body` — empty by convention. A canonical 5.0 place file has no `---` separator and no body section: the entire post-header content is the CTN frontmatter (one YAML mapping). The `---` separator and body section ARE permitted by the CTN parser and MAY be present without effect (a non-empty body in a place is ignored), but the migration-tool output and the recommended hand-written form is frontmatter-only.

**Why frontmatter-only.** A place file is a declaration, not a stream. Every key the implementation needs (routing directives, dispatcher selection, container references, region structure) is a YAML field on the place block — there is no second "content section" the way CTN documents elsewhere have a body. Putting the declaration in CTN frontmatter means one YAML parse for the whole file, with all keys reachable via `block.fields[<key>]`, regardless of whether the key is `@args` (resolved against URL parts before invocation) or `containers` (resolved by `place-ctn` during composition).

**Example (canonical form):**

```
CTN: place
@args: id = {{2}}
containers:
  - page-header
  - "article?id={{id}}"
  - page-footer
```

There is no `---` line. The CTN parser reads everything between the `CTN:` header and end-of-file as YAML into `block.fields`. The resulting `fields` mapping is `{"@args": "id = {{2}}", "containers": ["page-header", "article?id={{id}}", "page-footer"]}`.

**Migration note.** 4.x `.txt` stack files had a `---` separator dividing CTN frontmatter (where `@args` lived) from the body (the line-list of container refs). 5.0 unifies both halves into one YAML mapping in the CTN frontmatter; the body section is retired. The `core/tools/stack-to-place.php` migration tool emits frontmatter-only output.

## 4. URL → place resolution (normative)

### 4.1 Parts extraction

Given a URL path:

1. Strip leading and trailing `/`.
2. If the remaining string is empty, the URL parts are the single synthetic part `["index"]`.
3. Otherwise, split on `/` to produce the parts list.
4. **Suffix extraction.** If the last part contains `.`, split at the last `.`: the portion after is the **raw suffix**; the portion before replaces the last part.
5. **Suffix classification.** The raw suffix falls into one of two classes:
   - **Dispatch suffix** (framework-reserved, stripped before place matching, handed to the adapter for renderer selection): the set `{ctn, raw, place, placectn, htmx, trace}`. An adapter MAY define additional dispatch suffixes in configuration. (Historical note: 4.x used `stack` and `stackctn` for analogous purposes; 5.0 renames to `place` and `placectn`. Implementations MAY accept the 4.x names as compatibility aliases during the migration window.)
   - **Alias-default suffix** (stripped before place matching, treated as if no suffix was present): the set `{html}` by default; implementations MAY expose this set as configuration. Equivalence rule: a URL carrying an alias-default suffix resolves identically to the same URL without the suffix.
   - Any raw suffix that matches neither class is invalid at the adapter level. The adapter MUST treat such a URL as 404 unless the site explicitly configures additional suffix behavior.

A URL with no suffix (no `.` in the last part) skips §4.1.4 and §4.1.5 entirely.

### 4.2 Candidate enumeration

Let n be the number of URL parts. Generate all 2^n candidate patterns by choosing, for each position i, either the exact URL part or `*`. Encode each as a bitmask where bit i set means "position i is `*`".

### 4.3 Priority ordering

Sort candidates by, in this order:

1. **Ascending wildcard count.** Fewer `*` wins.
2. **Descending bitmask value.** Within the same wildcard count, a pattern with its `*`s pushed rightward wins over one with `*`s on the left.

The second rule resolves ties deterministically. For URL `/foo/bar`:

| Candidate  | Wildcards | Mask | Priority |
| ---------- | --------- | ---- | -------- |
| `foo--bar` | 0         | 00   | 1st      |
| `foo--*`   | 1         | 10   | 2nd      |
| `*--bar`   | 1         | 01   | 3rd      |
| `*--*`     | 2         | 11   | 4th      |

### 4.4 First-file-wins

Iterate through the sorted candidates. For each, test whether `<places-root>/<pattern>.place` exists. The first existing file is the resolved place. If no file exists, the URL does not match any place; the adapter handles this as a 404 or equivalent.

## 5. CTN frontmatter directives

Two reserved keys MAY appear in the CTN frontmatter (the YAML mapping that follows the `CTN: place` header). Both are resolved against URL parts at request time; the resolved values become available as named bindings to mods invoked from the place's regions. Because the place's entire declaration lives in the same frontmatter mapping (§3.1), these directives sit alongside the other top-level keys (`containers`, `regions`, `dispatcher`, etc.) as YAML siblings.

### 5.1 `@args`

`@args` is a CTN frontmatter field whose string value is a mini-grammar specifying bindings from URL parts (or literals) to named args:

```
@args: id = {{2}}, year = {{1}}, greeting = "Hello"
```

#### 5.1.1 Binding grammar

Each binding is `<name> = <spec>` where:

- `<name>` is an identifier (letters, digits, underscores, hyphens).
- `<spec>` is one of:
  - `{{<N>}}` — URL part number N, 1-indexed.
  - `"<literal>"` or `'<literal>'` — a quoted string literal.
  - `<bareword>` — an unquoted value treated as a plain string.

Whitespace around `=` and around bindings is permitted and trimmed.

#### 5.1.2 Binding resolution

For a place matched against URL parts `["foo", "bar"]`:

| Binding spec      | Bound value                |
| ----------------- | -------------------------- |
| `{{1}}`           | `"foo"`                    |
| `{{2}}`           | `"bar"`                    |
| `{{3}}`           | unbound (name absent from args) |
| `"Hello"`         | `"Hello"`                  |
| `greeting`        | `"greeting"` (bareword)    |

#### 5.1.3 YAML key quoting (implementation note)

The key `@args` begins with `@`, which YAML 1.2 lists as a character **reserved for future use**. Strict YAML 1.2 parsers refuse to start a document with a reserved character; lenient parsers (PHP's Spyc) accept it.

For cross-implementation portability, implementations MUST accept place files that carry `@args:` unquoted at line start. An implementation whose YAML parser is strict SHOULD preprocess the CTN block, replacing any line-start `@<word>:` with `"@<word>":` before parsing. The resulting parsed map key remains `@args` (without surrounding quotes), so downstream consumers look up the same field name regardless of parser strictness.

#### 5.1.4 Final args

The resolved named args MUST be merged with the raw numeric parts (`{{1}}`, `{{2}}`, … accessible as integer keys or string form, implementation's choice). Precedence: named bindings override numeric-key collisions if any.

Query string args from the HTTP request MAY also be merged by the adapter, after `@args` resolution (later sources win). This spec does not mandate query-string merging — that's an adapter policy.

### 5.2 `@federation`

A second reserved frontmatter directive. Value is a YAML mapping from producer origin (scheme-qualified) to URL-rebase policy:

```
CTN: place
@federation:
  http://konnexus.net: to-consumer
  https://someone-else.example: to-producer
---
containers:
  - http://konnexus.net/term-intro.ctn?id=stitches
```

- Keys: `<scheme>://<host>[:<port>]`. Exact match against the producer origin of each federation ref in this place; default ports implicit.
- Values: one of the string literals `off`, `to-producer`, `to-consumer`.
- Applies to every federation ref (direct or deferred) against a named producer in this place. Scope is the place in which it appears; not inherited across places.
- Absent directive, or producer present in the place but absent from the map: default policy `to-producer` applies.
- Unknown policy value: place fails to load; the implementation SHOULD emit a `CTN: error` block noting the bad value.

Full semantics — what gets rewritten, what doesn't, resource-URL handling, cache-key interaction — live in `FEDERATION-SPEC.md §13`. This section defines only the place-authoring surface.

## 6. YAML frontmatter — place-level keys

The CTN block's frontmatter (the YAML mapping after the `CTN: place` header) holds the place-level declaration. The keys defined in §5 (`@args`, `@federation`) sit alongside the keys defined here; this section covers the surface structure and per-place configuration. Recognized keys are below; unknown top-level keys are an error.

### 6.1 `PLACE` (optional)

Value: string. A short identifier for the surface. Used for logging, trace output, and implementation telemetry. When present, SHOULD match the filename stem (without `.place`) and the filename pattern's first non-wildcard component, but this is not enforced.

### 6.2 `purpose` (optional in `fixed`-only places; required when any region is fluid)

Value: string. Natural-language description of the surface's overall intent. The primary context an AI-realm adapter uses to understand what the surface is for.

```yaml
purpose: >
  a surface for writing and editing content, where the system
  assists the author with relevant tools and references as they draft
```

A `fixed`-only place (the stack-equivalent case) MAY omit `purpose`. Any fluid region MUST be accompanied by a top-level `purpose`.

The `purpose` field is a declaration of intent, not a prompt template. Implementations SHOULD use it as context for composition decisions. They SHOULD NOT inject it verbatim into LLM prompts.

### 6.3 `dispatcher` (optional, default `buffered`)

Value: string. One of `buffered`, `streaming`, or an implementation-defined name.

- **`buffered`** (default) — the renderer collects all containers' CTN output, parses the combined stream, dispatches through acts, and renders; the response is emitted in one write. Equivalent to 4.x web behavior.
- **`streaming`** — the renderer emits the page shell, then iterates regions in order, flushing each container's output as it is produced. Long-running container (e.g. an LLM call) writes to the wire incrementally without waiting for downstream regions. See §9.

An implementation MAY define additional dispatcher names. Unknown dispatcher value: place fails to load; the implementation SHOULD emit a `CTN: error` block noting the bad value.

### 6.4 `auth` (optional)

Value: string. The place's authentication posture for the request. The canonical values are `required` (unauthenticated requests are redirected to a login surface before the place is composed) and absent (no auth gate; the place is publicly readable).

Implementations MAY define additional values (`optional`, role names, etc.). The mechanism by which the adapter checks auth (session cookie, bearer token, both) is outside this spec.

### 6.5 `prg` (optional, default `auto`)

Value: string. One of `auto` or `off`. Controls whether the web adapter applies its POST-redirect-GET wrapper around POST requests to this place (`ACTS-SPEC.md` §7.1).

- **`auto`** (default) — the web adapter applies whatever PRG policy it ships. The 5.0 PHP reference auto-PRGs every non-HTMX, non-Bearer POST (POST → stash in session → 303 → consume on next GET). Other adapters MAY implement different policies.
- **`off`** — the adapter MUST dispatch POSTs to this place directly, without redirecting. The mod receives the original POST body in arg-lifecycle position 6 on the initial request. Intended for synchronous JSON APIs, file-upload endpoints, and any other write surface that returns a body the client expects to read (not a 303).

Browser-driven form POSTs use `auto`; JSON / multipart / machine-to-machine APIs use `off`. The key is per-place: a place file declaring `prg: off` opts the entire place out of PRG for all its POST traffic.

Realm note: this key is meaningful only to the web adapter (HTTP realm). Realms without redirect semantics (CLI, MCP, embedded) ignore it.

Unknown `prg` values: place fails to load; the implementation SHOULD emit a `CTN: error` block noting the bad value (same shape as the `dispatcher` rejection in §6.3).

### 6.6 `containers` (optional; mutually exclusive with `regions`)

Value: sequence of container references (§8). Declares a single implicit `fixed`-mode region containing this list. This is the **simple form** of a place: a flat list of containers with no per-region structure. The simple form is the 4.x `.txt`-stack equivalent.

A place file with `containers` at the top level MUST NOT also declare `regions`.

```yaml
CTN: place
@args: id = {{2}}
---
containers:
  - page-header
  - "article?id={{id}}"
  - page-footer
```

### 6.7 `regions` (optional; mutually exclusive with `containers`)

Value: mapping from region name to region declaration (§7). Declares one or more explicit regions, each with its own mode, container list, and per-region settings. This is the **multi-region form** of a place.

Region names are short identifiers: lowercase, hyphens permitted. Region names carry semantic meaning (`nav`, `tools`, `main`) but are not reserved — an application chooses its own.

```yaml
CTN: place
PLACE: reading
dispatcher: streaming
@args: id = {{2}}

purpose: >
  a reading surface for a single note

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
      - related-reads
```

A place file MUST declare exactly one of `containers` (simple) or `regions` (explicit). Declaring neither is an error.

## 7. Region schema (normative)

Each region (under `regions:` in the explicit form, or the synthetic single region in the simple form) is a YAML mapping with the following keys.

### 7.1 `mode` (required)

Value: one of `fixed`, `constrained-fluid`, `open-fluid`.

- **`fixed`** — Containers are pre-declared and do not change with intent. Equivalent to a traditional stack file's container list. Used where predictability and muscle memory matter.
- **`constrained-fluid`** — Containers in `containers:` (if present) are always included; containers in `candidates:` are the LLM accept-list, from which the LLM may select zero or more for this region this turn. The combined set is bounded; the LLM's contribution is in selection, ordering, and combination.
- **`open-fluid`** — Containers in `containers:` (if present) are always included; the LLM additionally selects from the application's full container library, with no `candidates:` accept-list constraining selection.

### 7.2 `containers` (required for `fixed`; optional for fluid modes)

Value: sequence of container references (§8). Always-included containers for this region.

For `fixed` mode, this is the ordered set of containers that compose the region.

For fluid modes, this is the always-included subset (typically chrome like a region header, or the `nexus` orchestrator mod itself).

### 7.3 `candidates` (forbidden for `fixed`; optional for `constrained-fluid`; forbidden for `open-fluid`)

Value: sequence of container references (§8). The accept-list from which an LLM-driven implementation may select containers for this region. Order serves as a hint (first = most typical); the LLM is free to reorder.

`open-fluid` regions MUST NOT have a `candidates:` key — selection is unconstrained by definition.

### 7.4 `purpose` (required for fluid modes; optional for `fixed`)

Value: string. Natural-language description of what this region is for. Guides the LLM's container selection within this region, scoped by the place's top-level `purpose`.

### 7.5 `pinnable` (optional, default `false`)

Value: boolean. Whether the user may pin a container in this region to prevent displacement across turns.

Only meaningful for fluid regions. If present on a `fixed` region, it is ignored.

Pinning is declared here; the interaction mechanism for pinning (gesture, command, UI element) is outside this spec.

### 7.6 `persist` (optional)

Value: boolean. Whether containers in this region survive across turns within a session.

Defaults: `true` for `fixed` regions, `false` for `constrained-fluid` and `open-fluid` regions.

The defaults reflect typical use: fixed regions persist (navigation stays), fluid regions re-evaluate each turn (the surface reflects what matters now). This is displacement by relevance — what no longer matters is simply not summoned.

## 8. Container references (normative)

A container reference identifies one mod or static container to invoke within a region. The grammar is identical to STACK-SPEC v2.0 §7 with the body-line context generalized to "any sequence value under `containers:` or `candidates:`".

### 8.1 Reference forms

A container reference has three parts, in order:

- an optional **prefix** (a directive marker, e.g. `htmx:`),
- a required **name** (an identifier or a `http(s)://…` URL),
- an optional **per-line args** suffix (a `?`-introduced, `&`-separated list of `key=value` bindings).

The three parts compose into these recognized forms:

- **Plain invocation.** `name`
  Resolves via the Core's namespace-flat dispatcher `$C->ctn(name, args)` — mod first, static-container fallback. The invoked mod/container receives the place's resolved args and emits CTN.
- **Invocation with per-line args.** `name?k1=v1&k2=v2…`
  As above, but with per-line args layered on top of the place's resolved `@args` dict before the mod/container is invoked.
- **Federation ref (direct).** `http://…` or `https://…`
  The implementation fetches CTN from that URL during render and inlines it. Allowlist-gated; see `FEDERATION-SPEC.md`.
- **Prefixed reference.** `<prefix>: <target>` or `<prefix>: <target>?k1=v1&…`
  A reference whose first `:` occurs **before** any `?` is a directive: the portion before the first `:` is the prefix (trimmed); the portion after is the target. Known prefixes:

  | Prefix   | Semantics                                                  |
  | -------- | ---------------------------------------------------------- |
  | `htmx:`  | emit an HTMX deferred-fetch placeholder block instead of resolving inline. Per-line args, if present, are forwarded into the generated fragment-fetch URL. The target after `htmx:` MAY be either a local mod/container name (lazy loading of local content) or a foreign URL starting with `http://` or `https://` (deferred federation — see `FEDERATION-SPEC.md §14`). |

  Unknown prefixes are an error: the implementation SHOULD emit a `CTN: error` block referencing the bad reference and continue composing. A conformant implementation MUST NOT silently drop or silently mis-resolve unknown prefixes.

When a container reference is written as a YAML string value, the entire reference (including any per-line args) is a single quoted-or-unquoted scalar:

```yaml
containers:
  - page-header
  - "article?id={{id}}"
  - "htmx: related-notes?id={{id}}"
  - http://konnexus.net/term-intro.ctn?id=stitches
```

### 8.2 Per-line args grammar

The optional suffix on a container reference is a single `?` followed by one or more `&`-separated bindings:

```
per-line-args ::= "?" binding ( "&" binding )*
binding       ::= name "=" value
name          ::= identifier (letters, digits, underscores, hyphens)
value         ::= ref | literal
ref           ::= "{{" ( <digits>+ | <name> ) "}}"
literal       ::= quoted | bareword
quoted        ::= '"' <any bytes except unescaped '"'> '"'
               |  "'" <any bytes except unescaped "'"> "'"
bareword      ::= <any chars except "&", quote chars, whitespace, "{", "}">
```

Whitespace around `=`, `&`, or `?` is NOT permitted (matches URL-query convention).

### 8.2.1 Value resolution

Per-line-arg values resolve against the place's already-resolved args (the output of §5.1 resolution) before the container is invoked.

- `{{N}}` (where N is one or more digits) → URL part N, identical semantics to §5.1.2. Always addressable, independent of whether `@args` named it.
- `{{<name>}}` → look up `name` in the place's resolved args. If absent, the binding is **unbound**: the key is omitted from the per-line args dict (it does not resolve to empty string).
- `quoted` literal → the bytes between the quotes, with `\"` / `\'` / `\\` escapes.
- `bareword` literal → the raw characters, unchanged.

Per-line-arg values are ALWAYS strings. No type coercion at this layer.

### 8.3 Composition

For each region, in declaration order; for each container reference in the region's `containers` (and, for fluid regions, the LLM-selected subset of `candidates`), in order:

1. Parse the reference into prefix (optional), name, and per-line args (optional).
2. If per-line args are present, resolve their values against the place's resolved args to produce the **per-line args dict**.
3. Compose the **invocation args** by merging the per-line args dict on top of the resolved args (per-line keys override place-wide keys; other resolved args still flow through untouched).
4. Plain invocation → invoke `$C->ctn(name, invocation_args)`; append the output to the combined CTN.
5. Federation ref → fetch per FEDERATION-SPEC; inline the resulting CTN.
6. Prefixed reference → handle per the prefix's semantics, passing `invocation_args` to the handler.

For each appended output, if it does not begin with `CTN:`, wrap it as `CTN: standard\n---\n<output>` before concatenation (the implicit-default rule from CTN-SPEC §4.1, applied at the composition boundary).

The resolved args from §5.1 are NOT mutated by per-line merging — each container reference sees a fresh composition (step 3). A mod invoked at one reference cannot observe the per-line args from another.

The result is a single CTN stream — a flat concatenation of blocks — handed to the acts dispatch.

## 9. Dispatcher variants (normative)

The `dispatcher` key (§6.3) selects how the renderer composes regions into the wire response. Two canonical dispatchers are defined; implementations MAY define additional named dispatchers.

### 9.1 `buffered` (default)

The renderer:

1. Iterates regions in declaration order, composing each (per §8.3) into a combined CTN string held in memory.
2. Parses the combined CTN into a block list.
3. Dispatches each block through the acts layer (see `ACTS-SPEC.md`).
4. Assembles the final response and emits it as a single write.

This is the canonical 4.x web behavior. Page shell, body, and head-slot writes (e.g. `CTN: title`) all reach their final form before anything is sent to the client. A `CTN: redirect` emitted by any region short-circuits the entire response cleanly.

### 9.2 `streaming`

The renderer:

1. Emits the page-shell head (opening HTML) immediately.
2. Iterates regions in declaration order. For each region, iterates its container references. For each reference, invokes the mod/container directly (no `ob_start` wrapping); the container's `echo`/`print` output flows to the wire as the container produces it. Containers that emit incrementally (a `flush()` after each chunk; an LLM call that token-streams) stream to the client in real time.
3. Emits the page-shell foot (closing HTML) after all regions complete.

Acts that depend on the *complete* CTN stream (`title` writing to the head, `redirect` short-circuiting) MUST either run before any streaming output begins (the renderer MAY pre-collect early-region output to discover them) or are deferred to the buffered dispatcher. Implementations SHOULD document their handling.

Streaming requires the response to be `Content-Type: text/html` (or a streaming-friendly type) and the realm to support incremental writes (HTTP server with output buffering disabled, no compressing proxy in the path). The adapter SHOULD set `X-Accel-Buffering: no` and `Cache-Control: no-cache, no-store, must-revalidate` to defeat upstream buffering.

### 9.3 Selection

A place selects its dispatcher via `dispatcher: <name>` in the place frontmatter. Absent the key, `buffered` applies. The implementation MUST reject a place with an unknown `dispatcher` value (per §6.3); it MUST NOT silently fall back.

A mod invoked from a place MUST NOT branch on which dispatcher composed the place. Dispatcher selection is a per-place property, not a per-mod input. Mods that emit incremental output (intended for streaming) MUST do so unconditionally; on a buffered dispatcher, the output is collected as usual and the streaming intent is harmless. (Drift signal: a mod that calls `function_exists('flush_chunk')` is asking which dispatcher it's running under — that's pillar 6 — pillar-8 drift. Each dispatcher provides its own primitive; the symbol always exists.)

## 10. Worked examples

### 10.1 Simple form — stack-equivalent

A 4.x `.txt` stack at `stacks/article--*.txt`:

```
CTN: stack
@args: id = {{2}}
---
page-header
article?id={{id}}
htmx: related-notes?id={{id}}
page-footer
```

becomes a 5.0 `.place` file at `places/article--*.place`:

```
CTN: place
@args: id = {{2}}
---
containers:
  - page-header
  - "article?id={{id}}"
  - "htmx: related-notes?id={{id}}"
  - page-footer
```

Mechanical conversion: replace `CTN: stack` with `CTN: place`; lift the 4.x body line-list into a YAML `containers:` sequence inside the CTN frontmatter (no `---` separator in the output — see §3.1); quote any reference containing `{`, `}`, `?`, or `:`.

### 10.2 Drafting surface — multi-region with fluid modes

A complete place file for a content-drafting surface:

```yaml
CTN: place
PLACE: drafting
dispatcher: streaming
@args: id = {{2}}
---
purpose: >
  a surface for writing and editing content, where the system
  assists the author with relevant tools and references as they draft

regions:
  nav:
    mode: fixed
    containers:
      - site-nav
      - breadcrumb

  input:
    mode: fixed
    containers:
      - draft-editor

  tools:
    mode: constrained-fluid
    purpose: >
      show writing tools relevant to the current stage of drafting —
      early drafts benefit from structure tools, later drafts from
      polish and export
    containers:
      - nexus
    candidates:
      - word-count
      - readability-score
      - link-checker
      - version-history
      - export-options
    pinnable: true

  main:
    mode: open-fluid
    purpose: >
      summon references and aids relevant to what the author is working
      on right now — search results, related content, link suggestions,
      formatting guidance, preview
    containers:
      - nexus
    pinnable: true
    persist: false
```

`nav` and `input` are fixed: always present, in declaration order. `tools` is constrained-fluid: `nexus` always runs (it's the orchestrator that emits the LLM's picks), and its picks must come from the `candidates:` list. `main` is open-fluid: `nexus` chooses freely from the application's full container library, scoped by the region's purpose.

The mix is the design surface — choosing which regions are fixed and which are fluid is the central design act.

## 11. Composition modes in context

### 11.1 Fixed as bridge

A place with only fixed regions (the simple `containers:` form, or a `regions:` form whose every region is `mode: fixed`) is equivalent to a traditional stack file. This is intentional: `fixed` is the bridge between 4.x stack files and the 5.0 place format. An instance can migrate `.txt` → `.place` mechanically, with every place starting as fixed-only, then introduce fluid regions incrementally where the surface benefits.

### 11.2 Constrained-fluid as bounded trust

The LLM selects from a declared `candidates:` set. The application author retains control over *what* can appear; the LLM decides *whether*, *when*, and *in what combination*. Appropriate when the containers for a region are known but their relevance varies.

The LLM may show zero, one, or several containers from the accept-list. It may reorder them. It may not introduce containers from outside `candidates:` (always-included `containers:` are unaffected).

### 11.3 Open-fluid as full delegation

The LLM selects from the application's full container library. Maximum flexibility; depends on reliable intent matching between user intent, region purpose, and container metadata.

Open-fluid regions SHOULD declare a focused `purpose`. A vague purpose ("show useful things") gives the LLM insufficient guidance and invites bland-content drift — containers that are generically relevant but specifically useless.

## 12. Validation rules (normative)

A conformant parser MUST validate place files against these rules:

1. The file is a valid CTN document and its first block has `type = "place"`.
2. The first block's CTN frontmatter is a valid YAML mapping. (The block's body, if present, is ignored — a canonical 5.0 place file has no body section.)
3. Exactly one of `containers:` (sequence) or `regions:` (mapping) is present at the frontmatter top level.
4. Every region (under `regions:`) is a YAML mapping with a `mode` key whose value is `fixed`, `constrained-fluid`, or `open-fluid`.
5. `fixed` regions have a `containers` key whose value is a non-empty sequence; they MUST NOT have `candidates`.
6. `constrained-fluid` regions MAY have a `containers` key (sequence, possibly empty) and SHOULD have a `candidates` key (non-empty sequence).
7. `open-fluid` regions MAY have a `containers` key (sequence, possibly empty); they MUST NOT have `candidates`.
8. `constrained-fluid` and `open-fluid` regions have a `purpose` key with a non-empty string value.
9. If any region is fluid (`constrained-fluid` or `open-fluid`), the place MUST have a top-level `purpose:` key.
10. If `dispatcher:` is present, its value is a string the renderer recognizes.
11. If `prg:` is present, its value is either `auto` or `off`.
12. No top-level key other than `@args`, `@federation`, `PLACE`, `purpose`, `dispatcher`, `auth`, `prg`, `containers`, `regions` is present in the frontmatter mapping. Unknown keys are errors, not silent pass-throughs.
13. No region key other than `mode`, `containers`, `candidates`, `purpose`, `pinnable`, `persist` is present. Unknown keys are errors.

Validation failures MUST surface as errors with the failing rule identified. A place file that fails validation MUST NOT be used to compose a surface.

## 13. Migration from `.txt` stacks (informative)

The 4.x → 5.0 cut archives `STACK-SPEC.md` (to `archive/4.x-stack-format/`) and retires the `.txt` stack format. Each instance migrating to 5.0 must convert its `stacks/*.txt` files to `places/*.place`. The conversion is mechanical for any stack that doesn't use a 5.0-removed surface:

| 4.x `.txt` stack | 5.0 `.place` |
|---|---|
| `CTN: stack` | `CTN: place` |
| `@args: …` (frontmatter) | `@args: …` (frontmatter) — unchanged |
| `@federation: …` (frontmatter) | `@federation: …` (frontmatter) — unchanged |
| body lines (one container ref per line) | `containers:` YAML sequence inside the CTN frontmatter (no `---` separator; PLACE-SPEC §3.1) |
| `stacks/` directory | `places/` directory |
| `.txt` extension | `.place` extension |

Reference implementations SHOULD ship a `stack-to-place` converter under `core/tools/` so instance migrations are one-shot.

The `STACK-SPEC.md` document is retained at `archive/4.x-stack-format/STACK-SPEC.md` in the meta repo as reference material for the migration window and for any language port (TS, Go) still at 4.x. Sites running 5.0 reference implementations MUST NOT continue to author new `.txt` stacks; the reference no longer reads them.

## 14. What this spec does not define

These are declared dependencies on work that does not yet exist, or work that lives in adjacent specs. A future session should not pretend they are settled.

- **Intent descriptor schema.** How containers declare what intentions they serve. Required for the LLM to match containers to fluid regions. This is metadata on the container, not on the place.
- **Intent expression format.** How user intent reaches the AI-realm adapter as input. The place declares what the surface does; the intent expression is what the user sends.
- **Confirmation act shape.** How consequential actions are gated. Confirmation is an act-layer concern, not a place-layer concern. See `ACTS-SPEC.md`.
- **Pinning interaction.** How a user pins a container. The place declares *whether* pinning is permitted (§7.5); the interaction design is outside this spec.
- **Trust calibration.** How the system adjusts confirmation behavior based on user experience.
- **Rendering and layout.** How regions are arranged on screen. Region names carry semantic meaning but this spec does not prescribe spatial arrangement. That is the realm adapter's concern.
- **Cross-realm mod invocation.** Whether an AI-realm adapter (likely Python/TS) can invoke PHP mods across processes.
- **The acts dispatch contract.** Lives in `ACTS-SPEC.md`. A composed place produces a CTN stream; what each block type does is the acts spec's job.

## 15. Versioning

This spec follows the versioning conventions established in `ACTS-SPEC.md` §10:

| Change shape | Version action |
|---|---|
| Additive (new optional region property, new composition mode, new dispatcher name, new top-level key) | Minor bump (1.0 → 1.1) |
| Breaking (required key changed, mode semantics changed, validation rule tightened, dispatcher contract changed) | Major bump |
| Clarification, examples, language cleanup | No bump; revision trail entry |

1.0 is normative. Breaking changes require a major bump; the 0.x permission for breaking-without-major has ended at promotion.

---

*A place is one URL → one composed surface. Containers within regions; regions within a place; the place dispatches through acts to the wire. The format declares the structure; the implementation animates it.*
