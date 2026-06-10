# CTN — Container Text Notation Specification

**Version 1.3**
**Status:** Normative. This document is authority for implementations.
**Scope:** CTN is a portable text format. No single implementation holds authority over it; this document does. The pre-manifesto PHP implementation at `reader.konnexus.net/lib/containerist/ctn.php` + `lib/yaml/yaml.php` (Spyc-based) is the historical reference for the format in practice; see §10. Future implementations (Go, Python, Rust, …) implement against this spec.

**Revision trail.**
- *1.3 (2026-04-24).* Added §4.7 body payload separator. A body line whose trimmed value is exactly `-----` (five hyphens) is the payload separator: consumers that subdivide a body for rendering MUST split there. The CTN framing layer is unchanged — `body` stays opaque at parse time and the block output shape remains `(type, fields, body)`. This reinstates an authoring pattern (content below a `-----` cut stays invisible to a default skin that binds only the first payload) that had been implicit in pre-manifesto practice but was dropped when CTN was formalized on 2026-04-20.
- *1.2 (2026-04-20, afternoon).* §8.2 switched from the YAML failsafe schema ("all scalars become strings") to the core schema (native types). The failsafe rule had been added to sidestep YAML 1.1 coercion hazards but imposed a larger cost at the template-rendering layer: the string `"false"` is truthy in Mustache, inverting author intent on every boolean field. The discipline shifts to authors, who MUST quote ambiguous scalars. See §8.2 rationale and `logs/2026-04-20-yaml-core-schema-reversal.md` for the narrative.
- *1.1 (2026-04-20, morning).* An earlier draft specified frontmatter as a flat-scalar key-value subset, based on reading `containerist-go/containerist.php::parse_ctn()`. That parser turns out to be an incomplete implementation — it does not call a YAML parser, so it silently fails on nested structures that real CTN content uses in production (see `reader.konnexus.net/containers/digests/*.txt`, which carry `items:` as lists of mappings). The spec was realigned to what the format actually is: YAML frontmatter. The `containerist-go` parser is tracked as a known defect; see `go-port/decisions.md`.

---

## 1. What CTN is

CTN (Container Text Notation) is a line-oriented text format for representing a flat, ordered list of typed content blocks. One CTN document contains zero or more blocks. Each block has:

- **type** — a string identifying the block's kind.
- **fields** — a YAML value (typically a mapping; may contain nested mappings, sequences, and scalars).
- **body** — an opaque text payload, typically Markdown or HTML.

CTN is the wire format of Containerist: every mod emits CTN, every static container stores CTN, every stack composes CTN. The format is small on purpose: a block header, YAML frontmatter, a `---` separator, a body.

## 2. Design goals

- **Line-oriented for boundaries, structured for data.** Block boundaries (`CTN:` headers and `---` separators) are line-level. Inside the frontmatter region, structure is YAML.
- **Flat at the block level.** Blocks are siblings, never nested. A document is a list of blocks. Block bodies are opaque to the CTN layer.
- **Structured inside frontmatter.** Fields may be scalars, sequences (lists), or mappings (maps), nested to arbitrary depth. CTN does not limit YAML's shape inside a block's frontmatter.
- **Portable.** The grammar is byte-exact across implementations at the CTN-framing level; YAML-parse output is equivalent-up-to-YAML-schema-equivalence (see §8). A conformant PHP parser and a conformant Go parser MUST produce semantically equivalent block lists for any input.
- **Implicit default.** A plain text file with no `CTN:` header is a valid CTN document containing one block of type `standard`, with empty fields and the entire input as body.
- **Human-editable.** Authors write stacks, static containers, and mod outputs by hand. YAML is a familiar frontmatter convention (Jekyll, Hugo, Zola, Astro); authors bring their YAML instincts.
- **Rejection-free at the CTN layer.** Every byte stream produces a defined block list. The YAML layer inside a block MAY reject malformed YAML; when it does, see §4.5.

## 3. Grammar

A CTN document is a byte stream. UTF-8 is the recommended encoding, but CTN does not prescribe one — byte preservation at the framing level is what matters. Line endings `\n`, `\r\n`, and `\r` are all accepted; conformant implementations normalize to `\n` before framing.

Informal grammar:

```
document        := implicit-form | explicit-form
implicit-form   := <any byte sequence that does not contain the substring "CTN:">
explicit-form   := discarded-prefix? block+
discarded-prefix := <any bytes before the first line that starts with "CTN:">
block           := header yaml-region (separator body-region)?
header          := "CTN:" WS? type NEWLINE
type            := <any bytes on the header line after "CTN:", trimmed of leading/trailing whitespace>
yaml-region     := <zero or more lines that are not "---" and do not start with "CTN:">
separator       := NEWLINE "---" NEWLINE     (a line whose trimmed value is exactly "---")
body-region     := <any bytes until the next line starting with "CTN:" or EOF,
                   with trailing newlines stripped>
```

A line "starts with `CTN:`" if the characters `C`, `T`, `N`, `:` appear at byte offset 0 of that line. The very first line of the document qualifies if those four characters appear at offset 0 of the document.

The `yaml-region` is passed to a YAML parser; see §4 and §8.

## 4. Parsing rules (normative)

### 4.1 Implicit block

If the input is **empty** (zero bytes), the output is the empty block list `[]`. See §6.1.

Otherwise, if the input does **not** contain the substring `CTN:` anywhere, the entire input forms a single block:

```
type   = "standard"
fields = {}          (empty mapping)
body   = <entire input, verbatim>
```

This is the implicit default. A plain-text static container with content `Hello, world` is a valid CTN document containing one `standard` block.

> *Note:* the implicit-form trigger is the absence of the substring `CTN:` anywhere in the input, not "absence at line start." An input that contains `CTN:` only inside body-like text will parse as explicit blocks and may produce surprising output. Authors of plain-text containers whose content needs to mention `CTN:` MUST write an explicit `CTN: standard` header.

### 4.2 Explicit blocks — framing

If the input contains at least one line starting with `CTN:`, the document is explicit. Split the input into segments at every line beginning with `CTN:`. Any content before the first such line is the **discarded prefix** and is ignored (emit a warning if desired, but do not raise an error).

For each segment (each corresponding to one block):

1. **Type extraction.** The type is the text after `CTN:` on the header line, with leading and trailing whitespace trimmed.
   - `CTN: standard` → type `"standard"`
   - `CTN:   redirect  ` → type `"redirect"`
   - `CTN:` (nothing after) → type `""`

2. **Frontmatter/body split.** After the header line, scan the remaining segment lines:
   - If a line's trimmed value is exactly `---`, that line is the **separator**. All earlier lines are the YAML region; all later lines are the body region.
   - If no `---` separator is found, all subsequent lines are the YAML region and the body region is empty.
   - The body region has trailing `\n` characters stripped (equivalent to `rtrim(body, "\n")`). Leading and interior newlines are preserved.

3. **Frontmatter YAML parse.** The YAML region is parsed as a YAML 1.2 document under the schema defined in §8.

   - If the YAML region is empty or whitespace-only, `fields` is the empty mapping `{}`. Conformant implementations MUST normalize a YAML parser's "null document" result to an empty mapping at this step.
   - If the YAML region parses to a scalar or sequence at the top level (not a mapping), `fields` is that value. Implementations MUST preserve whatever shape the YAML parser produces; they MUST NOT force mapping shape. (In practice, CTN blocks always use top-level mappings, but the spec does not require it.)
   - If YAML parsing fails, see §4.5.

4. **Body.** The body region is a raw string, preserved byte-for-byte (after line-ending normalization and trailing-newline trim).

### 4.3 Line endings

Before framing, conformant implementations normalize line endings:

- `\r\n` → `\n`
- A bare `\r` (not followed by `\n`) → `\n`
- `\n` → `\n` (unchanged)

This normalization applies to the entire input before any other processing. It ensures that a CTN document authored on Windows, macOS-classic, or Unix parses identically.

### 4.4 Why "body only, no separator" does not exist

A block with no `---` separator has `body = ""`. All non-header lines in the segment are treated as the YAML region. Authors who want a body MUST include a `---` separator, even if the frontmatter is empty:

```
CTN: standard
---
hello world
```

This is deliberate. It forces every block with a body to commit to the frontmatter/body split explicitly, and it keeps the framing layer simple: the body region is defined only in terms of the `---` separator.

### 4.5 YAML parse errors

If the YAML region fails to parse (malformed indentation, unterminated quoted string, invalid character sequence), conformant implementations MUST:

1. Not crash the whole document parse.
2. Produce a block with `type` preserved from the header, `fields = {"_yaml_error": "<human-readable error message>"}`, and `body = ""`.
3. Continue parsing subsequent blocks.

This lets a consumer detect malformed frontmatter without losing the document's overall structure. The magic key `_yaml_error` is reserved for this purpose; authors MUST NOT use it as a real field name.

### 4.6 Block termination

A block's body region ends at whichever comes first:
- The next line starting with `CTN:`, or
- The end of input.

**There is no escape mechanism for `CTN:` at line start inside a body.** A body that needs to contain the literal byte sequence `CTN:` at the start of any line is not expressible in CTN 1.0. In practice this is rare — bodies typically hold Markdown, HTML fragments, or plain prose. If this becomes painful, a future spec version will introduce an escape; until then, authors MUST avoid it.

### 4.7 Body payload separator

A body is an opaque string at the CTN framing layer; parser output preserves it byte-for-byte. The framing layer does NOT subdivide bodies, and the block output shape stays `(type, fields, body)` per §5. This section defines the agreed-upon split rule for consumers that *choose* to subdivide a body — so every such consumer agrees on where the cut lands.

The **payload separator** is a line whose trimmed value is exactly `-----` (five hyphens). Any consumer that subdivides a body MUST split there and nowhere else.

**Split semantics.** A consumer splitting a body produces an ordered list of **payloads**:

1. Scan the body for lines whose trimmed value is exactly `-----`.
2. With N such lines, produce N+1 payload substrings (some may be empty); separator lines themselves are discarded.
3. Each payload is byte-identical to the body region between its neighboring separators, with a single trailing `\n` stripped if present (consistent with §4.2.4 body newline handling).
4. An empty body produces `[]` (zero payloads), not `[""]`. Consumers MUST distinguish "no body" from "one empty payload".
5. A body with no separator lines produces `[body]` (single payload, equal to the full body).

**Template surface.** Skinners conventionally expose payload *i* as `{{body}}` / `{{markdown}}` for i=0 and `{{body<i>}}` / `{{markdown<i>}}` for i≥1 — e.g. `{{body1}}`, `{{markdown1}}`, `{{markdown2}}`. The template surface itself is a skin-layer concern; see the per-language skinner guide.

**Rationale — below-the-cut authoring.** Authors routinely attach material to a body that should not survive into the default render — private notes, drafts, follow-ups, to-self reminders. A skin that binds only `{{markdown}}` silently drops every payload after the first; a skin that binds `{{markdown1}}` etc. receives them explicitly. Split is consumer-opt-in: a consumer that never splits (RSS, full-text export, federation relay) sees the body whole, separators and all. This is the right default for export surfaces that must preserve authored content verbatim.

**Authoring caveat.** A body line whose trimmed value is exactly `-----` is reserved as the payload separator. A body that needs a literal line of five hyphens inside a payload MUST use an alternate form — four or six dashes, surrounding non-whitespace content, or an HTML fragment. Same trade-off as §4.6: no escape mechanism in this version; avoid the collision. Note also: Markdown setext heading underlines use `---` or `===` (any length); a line of exactly `-----` following a paragraph renders as a Markdown H2. Authors placing a separator SHOULD surround it with blank lines both to preserve its intent as a body-level cut and to keep Markdown from grabbing it as a heading underline.

## 5. Canonical output shape

A conforming parser produces, for a given input, an ordered list of blocks. Each block is a 3-tuple:

```
Block = {
  type:   string                // non-empty for implicit form ("standard");
                                // may be empty for malformed explicit form.
  fields: YAMLValue             // typically a mapping; may be sequence, scalar, or mixed.
                                // empty frontmatter normalizes to {} (empty mapping).
  body:   string                // may be empty; trailing \n stripped.
}

YAMLValue = string | sequence<YAMLValue> | mapping<string, YAMLValue>
```

Two parse outputs are **semantically equivalent** if and only if:

- They produce the same number of blocks in the same order.
- For each block:
  - `type` strings are byte-equal.
  - `fields` are YAML-equivalent per §8 (structure-equal, with scalars of matching type and value).
  - `body` strings are byte-equal.

Mapping insertion order SHOULD be preserved where the host language's map type supports it (Go's native maps do not; `yaml.v3`'s MapSlice does; Python 3.7+ dicts do; PHP associative arrays do). Conformance does not require mapping insertion-order preservation, only key-set-and-value equivalence.

## 6. Edge cases (enumerated)

This section enumerates the edge cases the reference implementation handles. Conformant implementations MUST produce semantically equivalent output for each. These cases form the starting conformance fixture suite (see §9). Where useful, the expected `fields` value is shown as JSON for clarity.

### 6.1 Empty input

- **Input:** `""`
- **Output:** `[]` (no blocks)

### 6.2 Plain text, no `CTN:` substring

- **Input:** `"Hello, world"`
- **Output:** one block, `type = "standard"`, `fields = {}`, `body = "Hello, world"`.

### 6.3 Whitespace-only input (no `CTN:` substring)

- **Input:** `"   \n\n"`
- **Output:** one block, `type = "standard"`, `fields = {}`, `body = "   \n\n"`.

### 6.4 Single explicit block, empty frontmatter, with body

- **Input:**
  ```
  CTN: standard
  ---
  Hello, world
  ```
- **Output:** one block, `type = "standard"`, `fields = {}`, `body = "Hello, world"`.

### 6.5 Single block with flat scalar frontmatter

- **Input:**
  ```
  CTN: standard
  class: y05
  ---
  ## Title
  ```
- **Output:** one block, `type = "standard"`, `fields = {"class": "y05"}`, `body = "## Title"`.

### 6.6 Block with no `---` separator (body empty, fields carry everything)

- **Input:**
  ```
  CTN: redirect
  to: /new-home
  status: 302
  ```
- **Output:** one block, `type = "redirect"`, `fields = {"to": "/new-home", "status": 302}`, `body = ""`. (Per §8.2 core schema, `status` is an integer. If the author wants the string `"302"`, they must write `status: "302"`.)

### 6.7 Nested sequence of mappings in frontmatter

- **Input:**
  ```
  CTN: items
  class: flex
  items:
    -
      title: "Eine Raumstation retten"
      url: https://example.com/post/1
      kicker: Anmut und Demut
    -
      title: "Wabi-Sabi"
      url: https://example.com/post/2
      kicker: Kopfzeiler
  ```
- **Output:** one block, `type = "items"`, `fields = {"class": "flex", "items": [{"title": "Eine Raumstation retten", "url": "https://example.com/post/1", "kicker": "Anmut und Demut"}, {"title": "Wabi-Sabi", "url": "https://example.com/post/2", "kicker": "Kopfzeiler"}]}`, `body = ""`.

This is the shape used in production by the reader.konnexus.net digest files. A parser that does not implement real YAML cannot produce this output.

### 6.8 Multiple blocks concatenated

- **Input:**
  ```
  CTN: standard
  section: intro
  ---
  Hello.
  CTN: standard
  section: body
  ---
  World.
  ```
- **Output:** two blocks with `type = "standard"`, `fields = {"section": "intro"}` / `{"section": "body"}`, bodies `"Hello."` / `"World."`.

### 6.9 Type-only block, no newline, no frontmatter

- **Input:** `"CTN: done"`
- **Output:** one block, `type = "done"`, `fields = {}`, `body = ""`.

### 6.10 Leading content before first `CTN:`

- **Input:**
  ```
  junk line
  CTN: standard
  ---
  body
  ```
- **Output:** one block, `type = "standard"`, `fields = {}`, `body = "body"`. Leading `"junk line"` is discarded.

### 6.11 Quoted string frontmatter values containing colons and special chars

- **Input:**
  ```
  CTN: link
  url: "https://example.com/path?q=a:b&r=c"
  text: "Colons: yes, quotes: \"also\""
  ---
  ```
- **Output:** `fields = {"url": "https://example.com/path?q=a:b&r=c", "text": "Colons: yes, quotes: \"also\""}`, `body = ""`. YAML handles quoting and escape semantics.

### 6.12 YAML comment in frontmatter

- **Input:**
  ```
  CTN: standard
  # This is a YAML comment
  key: value
  ---
  body
  ```
- **Output:** `fields = {"key": "value"}`, `body = "body"`. YAML comments are stripped by the YAML parser.

### 6.13 `---`-like lines requiring exact-match after trim

- **Input:**
  ```
  CTN: x
    ---  
  body-text
  ```
- **Output:** `fields = {}`, `body = "body-text"`. A line whose trimmed value is exactly `---` is the separator; surrounding whitespace is allowed.

### 6.14 Windows line endings normalized

- **Input:** `"CTN: x\r\nkey: value\r\n---\r\nbody\r\n"`
- **Output:** `fields = {"key": "value"}`, `body = "body"`. Line endings are normalized before framing.

### 6.15 Malformed YAML in frontmatter (error sentinel)

- **Input:**
  ```
  CTN: x
  key: "unterminated
  ---
  body
  ```
- **Output:** `type = "x"`, `fields = {"_yaml_error": "<parser-specific message>"}`, `body = ""`. Parsing continues past this block if there are more.

### 6.16 Inline flow-style mapping and sequence

- **Input:**
  ```
  CTN: x
  tags: [alpha, beta, gamma]
  meta: {author: ada, year: "2026"}
  ---
  ```
- **Output:** `fields = {"tags": ["alpha", "beta", "gamma"], "meta": {"author": "ada", "year": "2026"}}`, `body = ""`. YAML flow styles are supported by any YAML 1.2 parser.

### 6.17 Body with payload separators (parser preserves body verbatim)

- **Input:**
  ```
  CTN: standard
  ---
  Public above the cut.

  -----

  Private below the cut.
  ```
- **Output (parser level):** one block, `type = "standard"`, `fields = {}`, `body = "Public above the cut.\n\n-----\n\nPrivate below the cut."`. The `-----` line is preserved byte-for-byte in the body. CTN framing does NOT split; that is a consumer concern per §4.7.
- **Consumer-level split (§4.7, informative):** a skinner that opts in produces two payloads `["Public above the cut.\n", "\nPrivate below the cut."]` and binds them to `{{markdown}}` and `{{markdown1}}` respectively. A skin that references only `{{markdown}}` renders the first payload; the second is dropped.

## 7. What CTN is and is NOT

CTN **is**:

- A framing layer (`CTN:` headers, `---` separators, body regions) over YAML frontmatter and opaque body text.
- YAML 1.2 where the frontmatter region is concerned.

CTN **is not**:

- Not a YAML superset. A CTN document is not itself a valid YAML document — it contains multiple YAML documents interleaved with framing markers.
- Not nested at the block level. Blocks are siblings, never parents. A block's `fields` may contain nested structure; a block's `body` is opaque to CTN.
- Not encrypted, not signed. CTN is plaintext. Integrity and authenticity concerns belong to the transport or storage layer.
- Not the place file format. Places live in `places/*.place` and are CTN documents of type `place` whose body is a YAML declaration of regions and containers; the format-specific grammar is in `PLACE-SPEC.md`. (Pre-5.0: `stacks/*.txt`, plain line lists, NOT CTN documents — see the archived `archive/4.x-stack-format/STACK-SPEC.md`.)
- Not the `@in` mod-header format. `@in` is a mod source-file header (PHP comments today, Go struct tags or generated code for the Go port). It has its own spec — see `IN-SPEC.md`. CTN ends at the block wire format.
- Not a rendering format. CTN describes what content is; skinners render it to HTML, RSS, plaintext, or other outputs.

## 8. YAML schema (normative)

CTN pins the YAML dialect and type-resolution schema to eliminate cross-implementation drift.

### 8.1 YAML version

CTN 1.0 frontmatter is **YAML 1.2**. Implementations that support only YAML 1.1 (e.g. Spyc 0.5 in the pre-manifesto reader.konnexus.net implementation) are permitted as legacy parsers but are not strictly conformant; see §10 for the historical-implementation note.

### 8.2 Type-resolution schema

CTN uses YAML's **core schema** for scalar type resolution — the default of most modern YAML libraries. Scalars may parse as strings, booleans, integers, floats, or null. The structural types are string, sequence, and mapping.

- `123` → integer `123`.
- `3.14` → float `3.14`.
- `true`, `false` → booleans.
- `null`, `~`, empty → null.
- `"123"`, `"true"` → strings (quoted scalars never undergo tag resolution).
- `2026-01-04 10:12:51` → string (core schema, unlike YAML 1.1, does not resolve timestamps).

At the frontmatter-region top level, empty or null-document parses normalize to `{}` (empty mapping) per §4.2.3.

**Rationale.** CTN blocks flow directly into template engines (Mustache, Handlebars, Go `html/template`), which rely on native types to decide section rendering, truthiness, and numeric comparisons. A prior version of this spec (§8.2 pre-2026-04-20) mandated the failsafe schema — all scalars coerced to strings — to sidestep YAML 1.1's type-resolution hazards. In practice the cost of that rule showed up every time a field like `draft: false` reached a template: the string `"false"` is a non-empty, *truthy* value in most template engines, inverting the author's intent. Authors who want typed values are better served by writing typed YAML and quoting the narrow set of scalars whose surface form is ambiguous.

**Authoring discipline.** To stay portable across YAML 1.1 and YAML 1.2 parsers, authors MUST quote any scalar whose surface form could be mistaken for a non-string type by a legacy parser. Specifically:

- Country-code–shaped values: `country: "NO"`, `answer: "y"`, `flag: "off"` (YAML 1.1 resolves these as booleans).
- Version-shaped values: `version: "1.10"` (trailing-zero preserving).
- Zero-prefixed or sexagesimal-shaped values: `port: "022"`, `time: "12:34:56"`.
- Hex-shaped values that are meant as identifiers: `id: "0x1A"`.
- Any string that the author intends as a string but which happens to match a scalar type (e.g. a slug equal to `true`).

This discipline is the mirror of the failsafe rule: instead of the parser stripping all types, the author opts *out* of type resolution for the minority of fields that need it.

### 8.3 Implementation notes (pointer)

Modern YAML libraries in most target languages default to core-schema output. Conformant implementations typically need no post-processing.

Language-specific library recommendations and caveats live in the per-language implementation guides:

- **PHP:** `CTN-in-PHP.md`.
- **Go:** `CTN-in-Go.md`.

Future ports (Rust, Python, TypeScript, etc.) add their own `CTN-in-<Lang>.md` sidecar. Those documents are informative and answer to this portable spec.

Implementations SHOULD NOT post-process the parsed tree to coerce scalars to strings. Downstream consumers (templates, schema checks, export emitters) rely on native types to behave correctly.

## 9. Reference fixtures and conformance test suite

A conformance test suite is maintained at `conformance/ctn-fixtures/`. Each fixture is a pair of files:

- `<name>.ctn` — the input CTN document.
- `<name>.json` — the expected parse output, a JSON array of `{"type": ..., "fields": ..., "body": ...}` objects.

Conformant implementations MUST pass every fixture. The suite starts with the 17 edge cases enumerated in §6; additional fixtures SHOULD be added whenever a new edge case is discovered. A fixture added to the suite becomes normative immediately; all implementations must update to pass it.

JSON encoding:

- `"type"` is a string.
- `"fields"` is a JSON value (object, array, string, number, boolean, or null — nested as needed). Scalar types reflect §8.2 core-schema resolution.
- `"body"` is a string.

Bytes that are not valid UTF-8 in a fixture input MUST be escaped using JSON's `\uXXXX` mechanism in the expected output.

## 10. Historical implementations (pointer)

Historical-implementation notes — including the pre-manifesto `reader.konnexus.net` parser, the early `konnexus.net` flat-scalar parser, conformance-status assessments, and remediation paths — live in the per-language implementation guides:

- **PHP implementations:** `CTN-in-PHP.md` covers the `reader.konnexus.net` Spyc-based parser (pre-manifesto), the `konnexus.net` flat-scalar parser and its defect status, and remediation.
- **Go implementation:** `CTN-in-Go.md` covers `containerist-go`'s current parser state and intended shape.

Those documents are informative and answer to this spec on any conflict. They are kept separate to keep CTN-SPEC.md language-neutral — the normative authority both implementations must converge on.

## 11. Conformance requirements

An implementation is **conformant** if and only if:

1. It passes every fixture in `conformance/ctn-fixtures/`.
2. Its parse output is semantically equivalent (§5) to the reference output for every fixture input.
3. It rejects no input at the CTN framing layer. Every byte stream produces a defined block list. (YAML parse errors inside a block surface via the `_yaml_error` sentinel per §4.5.)
4. It preserves block order as given in the input.
5. It implements the core YAML schema per §8.2 — native types for scalars — and does not post-process scalars to strings.
6. It normalizes line endings per §4.3 before framing.

A **conformant emitter** produces CTN that, when fed to a conformant parser, yields the intended block list. Recommended emitter behavior:

- Emit `CTN: <type>` on its own line, followed by `\n`.
- Emit the YAML frontmatter as a valid YAML 1.2 document (flow or block style, implementation's choice).
- Emit `---` on its own line between frontmatter and a non-empty body.
- Omit the `---` separator when the body is empty (optional; both forms accepted by the parser).
- End the document with a single trailing `\n`.
- Never emit a line starting with `CTN:` inside a body — it would silently split the block on re-parse. Emitters SHOULD detect and reject such content.

## 12. Versioning

This is CTN **version 1.3**.

A version bump is a deliberate, breaking change to the parsing rules. It requires:

- A named motivation documented in `direction.md`.
- Updated conformance fixtures reflecting the new behavior.
- All supported implementations updated in lockstep.

Changes that do not alter parse output for any existing input (documentation, additional fixtures, clarifying notes) do not require a version bump.

## 13. Authoring guidance — where to put what

Advisory, not a conformance requirement. A parser does not check field vs. body assignment; authors are free to ignore the rule. But doing so shifts cost from the authoring moment to every consumer downstream.

CTN offers two homes for a block's content: **fields** and **body**. The division is not arbitrary — it shapes what downstream consumers have to do.

- **Structured data goes in fields.** If a consumer will read the content programmatically — iterate a list, look up a key, compare values — put it in frontmatter as YAML. A parsed CTN block gives the consumer a native list/map/string tree for free. No regex, no splitting, no "parse the body into records again." See §8 for the YAML schema in force.
- **Prose goes in body.** If the content is rendered text — Markdown to HTML, HTML fragment passed through, plain text displayed — put it in the body. A skinner or other downstream renderer treats it as opaque text.

Typical pairings:

```
CTN: article
title: "Hello"
published: 2026-04-20
---
# Hello

Some Markdown prose …
```

(prose with light structure in frontmatter)

```
CTN: items
class: flex
items:
  - { title: "A", url: /a }
  - { title: "B", url: /b }
```

(pure structure, no body)

**Anti-pattern.** Emitting structured data into the body as human-readable text — lines like `- name: Konstantin`, `- tone: warm` — forces every downstream consumer to parse by hand what the CTN parser could have given them as fields for free. If you find yourself writing a second parser for a CTN block's body, the content belongs in fields.

## 14. Known limitations (for a future 1.1 or 2.0)

Deliberate simplifications, not defects. Flagged as candidates for a future version.

- **No escape for `CTN:` at line start inside a body.** A body cannot contain the literal string `CTN:` at column 0 of any line. Authors must avoid it.
- **No type escape.** A type string cannot contain a newline; the type is always single-line.
- **No per-block `%YAML` directive.** CTN pins the YAML version and schema globally (§8); individual blocks cannot opt into a different YAML dialect.
- **No document-level metadata.** CTN has no preamble above the first block. If document-level metadata becomes necessary, it will likely take the form of a reserved first block with a known type.
- **No comment syntax at the framing layer.** Comments inside the YAML region are allowed (YAML `#` syntax); comments between blocks are not expressible.
- **Ambiguous scalars require quoting.** Under the core schema, unquoted values like `NO`, `1.10`, `022`, or `12:34:56` may be interpreted as bool, float, or int depending on the YAML version in use (1.1 vs 1.2). Authors bear the cost of quoting (§8.2 authoring discipline) in exchange for native typed values surviving the parse.

---

*CTN is the portable data-stream format. Containerist is one system built on it; other systems may follow. When in doubt, this spec is authority.*
