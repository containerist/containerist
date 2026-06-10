# STACK-SPEC — Stack File Specification

**Version 2.0**
**Status:** **DEPRECATED as of Containerist 5.0 (2026-05-21).** This document remains as historical reference material for the migration window. The canonical surface-declaration format is `PLACE-SPEC.md`. 5.0 reference implementations no longer read `.txt` stack files; sites running 5.0 MUST migrate `stacks/*.txt` → `places/*.place` (see PLACE-SPEC §13 for the conversion shape). Sites on 4.x continue to consume this spec until they upgrade.

> **Migration in one paragraph.** Each `.txt` stack becomes one `.place` file in `places/`. Replace the `CTN: stack` header with `CTN: place`; move the body lines under a YAML `containers:` sequence; quote any container reference containing `{`, `}`, `?`, or `:`. The `@args` and `@federation` frontmatter directives carry over unchanged. The filename grammar (`pattern--with--wildcards.txt` → `pattern--with--wildcards.place`) is identical. Reference implementations ship a `stack-to-place` converter under `core/tools/`. After migration, this document is no longer load-bearing for the instance.

**Scope:** Stack files bind URL paths to a composition of containers. A Containerist 4.x Stacker resolves an incoming URL to a stack file, composes its contained mods and static containers, and renders the result. No single implementation holds authority over the format; this document does.

A stack file is a CTN document (see `CTN-SPEC.md`) of type `stack`. This spec defines the stack-specific conventions layered on top of CTN: filename-to-URL matching, `@args` bindings, body-line semantics.

**Revision trail.**
- *Deprecated 2026-05-21 (Containerist 5.0).* No spec content changed in this revision; status flipped from Normative to Deprecated. The `.place` format defined in `PLACE-SPEC.md` v1.0 subsumes everything this spec describes (URL routing, `@args` binding, `@federation` directive, per-line args, federation refs) and adds region-based composition and dispatcher variants that `.txt` stacks cannot express. The decision to retire rather than coexist follows from the complexity-eval finding that a permanent two-format window is the largest avoidable instance cost. See `logs/2026-05-21-5-0-places.md`.
- *2.0 (2026-04-21).* Two coupled changes land together as one breaking revision.
  - **`{{N}}` / `{{name}}` reference grammar replaces `$N` / `$name`.** The `$`-sigil form read language-ish (PHP-ish / shell-ish) to a reader coming in cold. With a second implementation under development (`containerist-go`) consuming the same stack files, neutral grammar becomes load-bearing. `{{…}}` also aligns with the Mustache-style syntax the framework already uses in skin templates.
  - **Per-line args on container references.** A stack body line may now carry a query-string-shaped argument list (`name?k=v&k2=v2`) after the container name. Per-line args layer on top of the stack's `@args`-resolved dict before the container is invoked. Motivation: the same mod can now be invoked twice in one stack with different args (`digest-nav?placement=top` / `digest-nav?placement=bottom`), collapsing the duplicate-mod workaround that previously required two near-identical files.
  - Migration for authors: replace `$1` / `$name` in `@args` and any existing per-line args with `{{1}}` / `{{name}}`. Mechanical; one pass of `sed` with manual verify catches edge cases.
  - Alias-default suffix class introduced: `.html` is now stripped before stack matching (treated as identical to no suffix). See §4.1.

---

## 1. What a stack file is

A stack file is a plain-text file whose contents form a CTN document of type `stack`. Its **frontmatter** (optional) declares bindings from URL parts to named arguments. Its **body** is a newline-separated list of **container references** — the flat sequence of mods and static containers that compose a page.

A Stacker reads a stack file when it resolves an incoming URL and needs to know what content to produce.

## 2. Design goals

- **Flat.** A stack is a list, not a tree. No `include`, no nesting, no recursion.
- **URL-scoped.** URL parts simultaneously select the stack and bind as named args — two effects from one resolver pass.
- **CTN-native.** A stack file is itself a CTN document. The same parser used for any CTN content reads stacks. No second format.
- **Human-editable.** Plain text, no build step, no compile step.
- **Deterministic matching.** Wildcard priority is fully defined. No ambiguity between two stacks that could both match the same URL.
- **Language-neutral references.** Substitution grammar (`{{…}}`) reads the same to a PHP developer, a Go developer, an information architect, and an LLM.

## 3. File layout

Stack files live under an implementation-configured root (by convention `stacks/`). Each file has a `.txt` extension.

The filename (without `.txt`) is the **stack pattern**: a sequence of URL-part-matchers joined by `--`. Each matcher is either an exact string or the single-character wildcard `*`.

Examples:

| Filename            | Matches URL paths          |
| ------------------- | -------------------------- |
| `index.txt`         | `/` (empty path)           |
| `feed.txt`          | `/feed`                    |
| `n--*.txt`          | `/n/<anything>`            |
| `archive.txt`       | `/archive`                 |
| `archive--*.txt`    | `/archive/<year>`          |
| `archive--*--*.txt` | `/archive/<year>/<month>`  |
| `*--*.txt`          | any 2-part URL             |

## 4. URL → stack resolution (normative)

### 4.1 Parts extraction

Given a URL path:

1. Strip leading and trailing `/`.
2. If the remaining string is empty, the URL parts are the single synthetic part `["index"]`.
3. Otherwise, split on `/` to produce the parts list.
4. **Suffix extraction.** If the last part contains `.`, split at the last `.`: the portion after is the **raw suffix**; the portion before replaces the last part.
5. **Suffix classification.** The raw suffix falls into one of two classes:
   - **Dispatch suffix** (framework-reserved, stripped before stack matching, handed to the Stacker's dispatcher for special handling): the set `{ctn, raw, stack, stackctn, htmx, trace}`. A stacker MAY define additional dispatch suffixes in configuration.
   - **Alias-default suffix** (stripped before stack matching, treated as if no suffix was present): the set `{html}` by default; implementations MAY expose this set as configuration. Equivalence rule: a URL carrying an alias-default suffix resolves identically to the same URL without the suffix.
   - Any raw suffix that matches neither class is invalid at the stacker level. The Stacker MUST treat such a URL as 404 unless the site explicitly configures additional suffix behavior.

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

Iterate through the sorted candidates. For each, test whether `<stacks-root>/<pattern>.txt` exists. The first existing file is the resolved stack. If no file exists, the URL does not match any stack; the Stacker handles this as a 404 or equivalent.

## 5. Stack file content

A stack file is a CTN document. Per CTN-SPEC §4, it may be:

- **Implicit-form** (no `CTN:` header): the entire file is treated as one block of type `standard`. This is NOT a valid stack; the Stacker MUST reject or treat as 404.
- **Explicit-form**: the first block MUST have `type = "stack"`. Subsequent blocks, if any, are ignored.

A conformant stack file's first block has:

- `type = "stack"` (required)
- `fields["@args"]` (optional) — see §6
- `body` — see §7

## 6. `@args` frontmatter

`@args` is a CTN frontmatter field whose string value is a mini-grammar specifying bindings from URL parts (or literals) to named args. The value is a comma-separated list of bindings:

```
@args: id = {{2}}, year = {{1}}, greeting = "Hello"
```

### 6.1 Binding grammar

Each binding is `<name> = <spec>` where:

- `<name>` is an identifier (letters, digits, underscores, hyphens).
- `<spec>` is one of:
  - `{{<N>}}` — URL part number N, 1-indexed (so `{{1}}` is the first URL part).
  - `"<literal>"` or `'<literal>'` — a quoted string literal.
  - `<bareword>` — an unquoted value treated as a plain string.

Whitespace around `=` and around bindings is permitted and trimmed.

### 6.2 Binding resolution

For a stack matched against URL parts `["foo", "bar"]`:

| Binding spec      | Bound value                |
| ----------------- | -------------------------- |
| `{{1}}`           | `"foo"`                    |
| `{{2}}`           | `"bar"`                    |
| `{{3}}`           | unbound (name absent from args) |
| `"Hello"`         | `"Hello"`                  |
| `greeting`        | `"greeting"` (bareword)    |

### 6.3 YAML key quoting (implementation note)

The key `@args` begins with `@`, which YAML 1.2 lists as a character **reserved for future use**. Strict YAML 1.2 parsers (including Go's `gopkg.in/yaml.v3`) refuse to start a document with a reserved character. Lenient parsers (such as PHP's Spyc) accept it silently.

For cross-implementation portability, Stackers MUST accept stack files that carry `@args:` unquoted at line start. An implementation whose YAML parser is strict SHOULD preprocess stack-file content, replacing any line-start `@<word>:` with `"@<word>":` before handing the text to its CTN parser. The resulting parsed map key remains `@args` (without the surrounding quotes), so downstream consumers look up the same field name regardless of the parser's strictness.

This preprocessing lives in the Stacker, not in the CTN parser — a CTN document that happens to use `@`-prefixed keys outside a stack file is rare and can be quoted by the author. Stacks are the one place where the convention predates the strict-YAML rule, so the Stacker handles it transparently.

### 6.4 `@federation` directive (fed-rebase policy)

A second reserved frontmatter directive. Value is a YAML mapping from producer origin (scheme-qualified; same form as an allowlist entry) to URL-rebase policy:

```
CTN: stack
@federation:
  http://konnexus.net: to-consumer
  https://someone-else.example: to-producer
---
```

- Keys: `<scheme>://<host>[:<port>]`. Exact match against the producer origin of each federation ref in this stack; default ports implicit.
- Values: one of the string literals `off`, `to-producer`, `to-consumer`.
- Applies to every federation ref (direct or deferred, see §7) against a named producer in this stack. Scope is the stack in which it appears; not inherited across stacks.
- Absent directive, or producer present in the stack but absent from the map: default policy `to-producer` applies.
- Unknown policy value: stack fails to load; Stacker SHOULD emit a `CTN: error` block noting the bad value.

Full semantics — what gets rewritten, what doesn't, resource-URL handling, cache-key interaction — live in `FEDERATION-SPEC.md §13`. This section defines only the stack-authoring surface.

### 6.5 Final args

The resolved named args are **merged with** the raw numeric parts (`{{1}}`, `{{2}}`, … accessible as integer keys or string form, implementation's choice). Precedence: named bindings override numeric-key collisions if any.

Query string args from the HTTP request MAY also be merged by the Stacker, after `@args` resolution (later sources win). This spec does not mandate query-string merging — that's a Stacker policy.

## 7. Body — container reference list

The body of a stack block is a newline-separated list of container references.

### 7.1 Line types

For each line, after trimming leading and trailing whitespace:

- **Empty line** — skipped.
- **Comment line** — starts with `#` after trim. Skipped.
- **Container reference** — any other non-empty line. Processed per §7.2.

### 7.2 Container reference forms

A container reference has three parts, in order:

- an optional **prefix** (a directive marker, e.g. `htmx:`),
- a required **name** (an identifier),
- an optional **per-line args** suffix (a `?`-introduced, `&`-separated list of key=value bindings — see §7.3).

The three parts compose into these recognized forms:

- **Plain invocation.** `name`
  Resolves via the Core's namespace-flat dispatcher `Ctn(name, args)` — mod first, static-container fallback (CTN pillar 7). The invoked mod/container receives the stack's resolved args and emits CTN; the emitted CTN is concatenated into the page's combined CTN output.

- **Invocation with per-line args.** `name?k1=v1&k2=v2…`
  As above, but with per-line args layered on top of the stack's resolved `@args` dict before the mod/container is invoked. See §7.3 for the grammar and §8 for the layering semantics.

- **Prefixed reference.** `<prefix>: <target>` or `<prefix>: <target>?k1=v1&…`
  A line whose first `:` occurs **before** any `?` is a directive: the portion before the first `:` is the prefix (trimmed); the portion after is the target, which itself may carry per-line args. Known prefixes:

  | Prefix   | Semantics                                                  |
  | -------- | ---------------------------------------------------------- |
  | `htmx:`  | emit an HTMX deferred-fetch placeholder block instead of resolving inline. Per-line args, if present, are forwarded into the generated fragment-fetch URL. The target after `htmx:` MAY be either a local mod/container name (lazy loading of local content) or a foreign URL starting with `http://` or `https://` (deferred federation — see `FEDERATION-SPEC.md §14`). |

  Unknown prefixes are an error: the Stacker SHOULD emit a `CTN: error` block referencing the bad line and continue composing. A Stacker MUST NOT silently drop or silently mis-resolve unknown prefixes.

A `?` that appears before the first `:` is part of the name-with-args portion, not part of a prefix delimiter. A name MAY NOT contain `?` or `:`.

### 7.3 Per-line args grammar

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

Whitespace around `=`, `&`, or `?` is NOT permitted (matches URL-query convention and avoids grammar ambiguity with trailing spaces). Authors who need whitespace, `&`, `?`, `{`, or `}` *inside* a value MUST use a quoted literal.

### 7.3.1 Value resolution

Per-line-arg values resolve against the stack's already-resolved args (the output of §6 resolution) before the container is invoked.

- `{{N}}` (where N is one or more digits) → URL part N, identical semantics to §6.1. Always addressable, independent of whether `@args` named it.
- `{{<name>}}` → look up `name` in the stack's resolved args. If the name is absent, the binding is **unbound**: the key is omitted from the per-line args dict (it does not resolve to empty string).
- `quoted` literal → the bytes between the quotes, with `\"` and `\'` recognized as escapes for the matching quote character and `\\` recognized as a literal backslash. No other escapes.
- `bareword` literal → the raw characters, unchanged.

Per-line-arg values are ALWAYS strings. No type coercion is applied at this layer — the grammar mirrors URL-query convention, where `refresh=true` carries the string `"true"`, not the boolean. A mod that wants a typed value is responsible for coercion.

### 7.3.2 Examples

```
digest?date={{1}}
digest?date={{date}}
digest-nav?refresh=true&date_to_refresh={{1}}
nav?items="one, two"&layout=grid
htmx: taps-count?id={{note_id}}
```

### 7.3.3 Edge cases

- **Empty value** (`?k=&…`) — `k` is bound to the empty string `""`.
- **Repeated key** (`?k=a&k=b`) — the last wins. Stackers MUST NOT silently concatenate.
- **Unknown `{{name}}`** — the key is absent from the per-line args dict. The stack's resolved `@args` may still supply a value for that key; if not, the mod's `@in` handles it per IN-SPEC (missing-required surfaces as error, default applies if declared).
- **Colon inside per-line args** — allowed only inside a quoted literal. An unquoted colon would confuse the prefix rule; the grammar rejects it.

### 7.3.4 Relationship to Mustache

`{{…}}` in skin templates (Mustache-style) substitutes a value at render time and may HTML-escape it. `{{…}}` in stack `@args` or per-line args substitutes a value at composition time and never escapes. Same visual syntax, different layer. Stack files never contain skin templates; skin templates never contain stack refs. The two contexts do not mix.

## 8. Composition — stack → combined CTN

Given a resolved stack file and URL parts, a Stacker produces combined CTN by:

1. Parse the stack file as a CTN document. Take the first block; verify `type == "stack"` (per §5).
2. Parse its `@args` frontmatter (if present) against the URL parts to produce the **resolved args** (per §6). These are the stack-wide args, available to every container reference in the body.
3. For each body line, in order (per §7):
   - a. Parse the line into prefix (optional), name, and per-line args (optional) per §7.2.
   - b. If per-line args are present, resolve their values against the resolved args (per §7.3.1) to produce the **per-line args dict**.
   - c. Compose the **invocation args** by merging the per-line args dict on top of the resolved args (per-line keys override stack-wide keys; other resolved args still flow through untouched).
   - d. Plain invocation → invoke `Ctn(name, invocation_args)`; append the output to the combined CTN.
   - e. Prefixed reference → handle per the prefix's semantics, passing `invocation_args` to the handler.
4. For each appended output, if it does not begin with `CTN:`, wrap it as `CTN: standard\n---\n<output>` before concatenation (the implicit-default rule from CTN-SPEC §4.1, applied at the composition boundary).

The resolved args from step 2 are NOT mutated by per-line merging — each body line sees a fresh composition (step 3c). A mod invoked at one line cannot observe the per-line args from another line.

The result is a single CTN string — a flat concatenation of blocks — ready for downstream rendering, control-flow inspection, or pass-through.

## 9. Edge cases

### 9.1 Empty body

A stack with an empty body (just `CTN: stack\n---\n`) composes to an empty CTN document. The Stacker MAY emit a blank response or insert a default block; this spec does not mandate.

### 9.2 No `---` separator

A stack file `CTN: stack\n@args: id = {{2}}` (no separator, no body) has empty body per CTN-SPEC §4.4. It composes to empty CTN. Valid.

### 9.3 First block is not `stack`

A file whose first CTN block has a type other than `stack` is not a valid stack file. The Stacker MUST reject it (404 / error, implementation's choice) rather than treating the body as a container list.

### 9.4 `@args` referencing nonexistent URL part

A binding `id = {{5}}` on a 2-part URL produces no `id` binding. The name is absent from args; mods requiring it via `@in` see a missing-required error per their language's convention.

### 9.5 Container resolution fails

A body line references a name that resolves to neither a mod nor a static container. The Stacker SHOULD emit a `CTN: error` block naming the bad reference and continue. This matches the briefing's control-flow-as-data philosophy.

### 9.6 Filename with `.` in a part

URL parts carry dots freely (e.g. `/foo.bar`), but the last part's dot is consumed as a suffix per §4.1. A URL `/foo.bar/baz` has parts `["foo.bar", "baz"]` and no suffix (the final `.` is not in the last part). A URL `/foo.bar` has parts `["foo"]` and raw suffix `"bar"`, which would fail suffix classification per §4.1.5 (neither dispatch nor alias-default) and return 404 — the stack author writes `foo--bar.txt` (2-part pattern) to match URLs like `/foo/bar` with trailing dot-extension semantics reserved for suffix-classed handling.

### 9.7 Same mod invoked multiple times in one stack

With per-line args (§7.3), a stack body MAY reference the same mod on multiple lines with different per-line args. Each invocation is independent — the mod is called once per line, each time with that line's composed invocation args. Example:

```
CTN: stack
@args: date = {{1}}
---
digest-nav?placement=top
digest-title
digest?date={{date}}
digest-nav?placement=bottom&refresh=true
```

`digest-nav` is invoked twice; each invocation receives a different `placement` value. This replaces a prior-era workaround where authors maintained two near-duplicate mods (e.g. `digest-nav` and `digest-nav-footer`) purely to vary one argument.

### 9.8 Per-line arg referencing unresolved name

A per-line-arg ref like `{{date}}` when the stack's resolved args contain no `date` key produces NO binding for that per-line arg key (per §7.3.1). If the invoked mod requires the key via its `@in`, it surfaces as a missing-required error per IN-SPEC. If the mod has no requirement on that key, the invocation proceeds with the key absent.

### 9.9 URL with alias-default suffix

`/articles/2026/foo.html` has last part `"foo"` and raw suffix `"html"`. Per §4.1.5, `html` is an alias-default suffix and is stripped. The URL resolves identically to `/articles/2026/foo`. Any matching stack sees the same parts `["articles", "2026", "foo"]` either way. Authors MUST NOT write a distinct stack file for the `.html` variant — it is semantically the same URL.

## 10. What stack files are NOT

- **Not nested.** No `include`, no recursion. A stack is a list.
- **Not conditional.** No `if`, no `when`. All flow control lives in mods.
- **Not Turing-complete.** A stack is a declarative list of content.
- **Not CTN blocks themselves, as far as their body is concerned.** The stack file IS a CTN document, but the body of its `stack`-typed block is interpreted as a line list, not as further CTN. A line that happens to start with `CTN:` is still treated as a container reference (which would fail to resolve, becoming an error block per §9.5).
- **Not the `@in` format.** `@args` (§6) and per-line args (§7.3) bind URL parts and literal values into a caller-side args bucket. `@in` declares what names a mod accepts out of that bucket. See `IN-SPEC.md`.
- **Not the skin format.** Skins (`skin/<type>.html` + `<type>.css`) render parsed CTN blocks to output. Stacks only compose; rendering happens after. See SKIN-SPEC (future).

## 11. Versioning

This is STACK version 2.0.

A version bump is a deliberate, breaking change to resolution, matching, or composition rules. It requires:

- A named motivation documented in `direction.md`.
- Updated conformance fixtures, if a conformance suite exists.
- All supported Stackers updated in lockstep.

## 12. Known limitations (for a future 2.1 or 3.0)

- **Only one wildcard character.** `*` matches one URL part exactly. No `**` for multi-part wildcards. URLs of variable depth need separate stack files per depth. (*The variable-depth case was considered and decided against; see `direction.md` § Decisions not to do.*)
- **No URL query matching for stack selection.** Query strings on the incoming HTTP request (`?q=foo`) don't participate in stack selection. They may be merged into args by the Stacker, but cannot influence which stack is chosen. (Separate from the per-line args syntax introduced in 2.0, which is inside the stack body and has nothing to do with HTTP query strings.)
- **No per-method binding.** Stacks match on path only. HTTP method (GET vs POST) is handled elsewhere (suffix routes, or edge-mod dispatch).
- **Filename encoding.** `--` is the delimiter; a literal `--` inside a URL part would confuse matching. Spec assumes URL parts never contain `--` — enforced by convention, not by the grammar.
- **Per-line-arg values are always strings.** No type coercion at the stack layer (§7.3.1). If a future version wants to allow typed literals in per-line args, the grammar would need explicit type markers — intentionally deferred.
- **Single alias-default suffix class member (`html`).** Additional alias-defaults (e.g. `htm` for legacy URLs) are implementation-config territory today; the spec names `html` as the default set.

---

*A stack is a list of invocations. Each line names a container and (optionally) how it's called.*
