# IN-SPEC — `@in` Mod-Header Specification

**Version 1.1**
**Status:** Normative. This document is authority for implementations.
**Scope:** `@in` is a mini-grammar embedded as a header (typically a comment) at the top of a mod source file. It declares the named arguments the mod accepts and, for each, whether it is required and what default applies if absent. A Containerist implementation parses `@in` headers at mod-registration or invocation time and uses them to filter and validate caller-supplied args before invoking the mod's body. No single implementation holds authority over the format; this document does.

The `@in` grammar is portable across implementation languages: PHP comments today, Go struct tags or a generated sidecar for the Go port, Rust attribute macros for a future Rust port. The *syntax inside the `@in:` payload* is the same, and the *parsed meaning* is identical. An implementation may choose its own source-file carrier (comment, annotation, attribute), but the payload between `@in:` and the trailing newline follows this spec.

---

## 1. What an `@in` header is

An `@in` header declares the named arguments a mod accepts. Each declaration records:

- the argument's **name** — how the mod refers to it;
- zero or more **qualifiers** — `required`, `optional`, `default: <literal>`.

A conformant runtime parses the header once per mod, produces a schema (a map from name → {required, default}), and applies the schema to the caller's supplied args on every invocation: filter unnamed keys out, apply defaults to missing keys, enforce required declarations.

## 2. Design goals

- **Local to the mod.** A reader opening a mod file sees the mod's entire input contract at the top, without cross-file lookups.
- **Uniform across realms.** The same `@in` declaration serves every caller (web adapter, cli adapter, mcp adapter, test harness, HTTP wrapper). The caller is responsible for supplying a named-args bucket; the mod and this spec don't care how the caller built it.
- **Realm-oblivious.** `@in` makes no reference to URL positions, query-string vs POST origins, CLI argv positions, or any realm-specific concept. A mod's declared input surface is names only. Where each name's value came from is the caller's concern, not the mod's.
- **Readable.** One line per declaration; grammar is small and visual. An author writes `note_id (required)` and a reader parses it without consulting documentation.
- **Explicit over implicit.** A mod cannot silently absorb caller-supplied args. Every accepted arg is named in `@in`; unnamed args never reach the mod's body.
- **Portable.** Grammar is language-independent. PHP/Go/Rust implementations parse the same byte payload.

## 3. File location

An `@in` declaration MUST appear near the top of the mod source file. Conformant implementations SHOULD scan at least the first 30 lines. A file with no `@in` declaration and no `@in:` marker line at all is treated as **undeclared** — implementations MAY reject it, warn, or fall back to implementation-defined behavior. A file with a line matching `@in:` (possibly followed by whitespace and/or a no-inputs marker — see §5.3) but no named inputs is declared as **empty**, which is semantically distinct from undeclared.

The surrounding carrier syntax is language-dependent. Any mechanism whose surface is a single line whose trimmed value starts with `@in:` (after comment-strip) is acceptable. See the per-language implementation guides (e.g. `IN-in-PHP.md`) for carrier details.

The grammar of the payload — everything after `@in:` up to the trailing newline, trimmed — is the subject of this spec.

## 4. Grammar

Informal grammar for the payload:

```
payload        := empty-marker | declaration-list
empty-marker   := "(nothing)" | "(none)" | ""
declaration-list := declaration ( "," declaration )*
declaration    := name qualifiers?
name           := <identifier: letters, digits, underscores, hyphens>
qualifiers     := "(" qualifier-list ")"
qualifier-list := qualifier ( "," qualifier )*
qualifier      := "required" | "optional" | default-qualifier
default-qualifier := "default" ":" literal
literal        := quoted-string | boolean | null | number | bareword
quoted-string  := '"' <any bytes except unescaped '"'> '"'
               |  "'" <any bytes except unescaped "'"> "'"
boolean        := "true" | "false"
null           := "null"
number         := <decimal integer or decimal float>
bareword       := <any non-whitespace chars except "," "(" ")">
```

Whitespace around structural tokens (`=`, `,`, `(`, `)`, `:`) is permitted and ignored.

### 4.1 Declaration ordering

Declarations in a header are comma-separated. The comma MAY NOT appear inside parentheses (qualifiers) or quoted literals — conformant parsers split on top-level commas only.

Example:

```
@in: id (required), limit (default: 10), admin (optional), format
```

Parses as four declarations:

1. `id`, required.
2. `limit`, default `10` (integer).
3. `admin`, optional (explicit; identical to no qualifier).
4. `format`, no qualifier (equivalent to `optional`; no default).

### 4.2 Literals (in `default:` qualifiers)

Literal grammar follows standard programming-language conventions:

- `"string"` and `'string'` — quoted strings; `\"`, `\'`, `\\` are recognized escapes, no others. Returns the unescaped string.
- `true`, `false` — booleans.
- `null` — the null value.
- Decimal number — integer (`42`, `-7`) or float (`3.14`, `-0.5`) per the host language's native type.
- Bareword — any other non-whitespace token. Returns as a string.

**Scalar-only rule.** `default:` literals MUST be scalars — one of the five forms above. **List- and map-shaped literals are NOT expressible** at the `@in` header. A token sequence like `default: []` or `default: {a: 1}` is not in the grammar; parsers MAY reject it outright or MAY treat it as a bareword string (implementation choice; neither interpretation is useful). A mod that needs a list- or map-shaped default declares the arg `(optional)` and initializes the value on line 1 of the mod body. Per-language idiom: see `IN-in-<Lang>.md`.

The rule is deliberate and conservative. Scalar defaults cover the overwhelming majority of cases (optional limit, optional flag, optional tag-string) without asking the `@in` grammar to grow a YAML-ish literal sublanguage with its own quoting, nesting, and escaping. Expressiveness for richer defaults belongs in the host language; the `@in` header stays a flat, reviewable declaration.

### 4.3 What `@in` does NOT grammar

`@in` declarations do NOT include **source references**. There is no LHS syntax like `name = {{1}}` or `name = {{query}}` or `name = $something`. The sole "source" of a name's value is the caller's supplied named-args bucket. The caller — adapter, CLI, MCP adapter, test harness — is responsible for populating that bucket from whatever realm-specific inputs it knows (URL parts, query string, POST body, argv, JSON tool-call args, etc.). The mod declares only *what names it accepts*; never *where the values come from*.

This is deliberate. A mod is realm-agnostic. Adding source syntax to `@in` would couple mods to specific realms (URL positions exist only in HTTP; CLI argv positions differ; MCP has no positional concept at all) — an impurity that briefing pillar 8 (realm-free) and its corollary forbid.

See `direction.md` § Decisions not to do for the explicit record on URL-position bindings and source chains.

## 5. Resolution semantics (normative)

### 5.1 Inputs to the resolver

A caller supplies a **named-args bucket**: a map from string keys to values (string or native-typed per the caller's convention). That is the only input.

### 5.2 Per-declaration resolution

For each declaration in the schema:

1. Look up the declared name in the caller's named-args bucket.
2. If the lookup returns a non-empty value (per §5.3), that value is the **resolved value**.
3. Otherwise:
   - If a `default:` literal is declared, the default is the resolved value.
   - Otherwise the declaration is **unresolved**.
4. If the declaration is `required` and unresolved, the runtime MUST surface an error per §5.4.
5. For every resolved declaration, the resolved value is placed under the declaration's name in the **invocation args** map passed to the mod body.

### 5.3 Empty-value rule

A value is **empty** (treated as absent for resolution purposes) if and only if:

- the key is not present in the bucket, OR
- the value is `null`, OR
- the value is the empty string `""`.

A value of `0`, `false`, `"0"`, or `"false"` is NOT empty — the core-schema truthiness conventions are irrelevant here. Only the three cases above count as empty.

This rule matches existing practice across implementations and exists because the common failure mode in realm-supplied args is "key absent" or "empty string" — not "zero" or "false."

### 5.4 Error semantics

When a required declaration is unresolved, the runtime MUST raise an error in a way appropriate to the host language (throw an exception, return an error value, emit a typed CTN error block, etc.). The error MUST name the missing input.

The PHP reference implementation prior to 5.1 used `throw new InvalidArgumentException("Missing required input: <name>")`, surfaced by the adapter layer and wrapped into a `CTN: error` block at the act boundary.

**Enforcement is a Core responsibility (1.1+).** The schema check belongs at the Core's invocation boundary, not the adapter's, so that every call into a mod — from any realm, any adapter, any in-process caller — runs the same enforcement once. A conformant Core MUST apply the `@in` schema (filter undeclared keys per §5.6, apply defaults per §5.2, enforce required per this section) on every mod invocation. The PHP reference at framework 5.1 collapsed two methods (`$C->mod()` with light intersect-key filtering; `$C->ctn()` with throw-on-lookup) into one (`$C->ctn()`) that owns enforcement and returns a `CTN: error` block with `code: 400` on missing required — surfacing schema violations to the caller as typed output rather than as language-level exceptions. Other implementations MAY surface schema violations via their native error conventions; the *behavior* (fail fast, name the input, run once at the Core boundary) is required, the *shape* is not. Adapter-level pre-filtering or repeated enforcement at multiple call sites is a non-conformance (the duplication 5.1 collapsed in the PHP reference).

### 5.5 Empty declaration (no-inputs marker)

A mod that accepts no inputs declares:

```
@in: (nothing)
```

or

```
@in: (none)
```

Both produce the empty schema `{}`. Distinct from undeclared (no `@in:` line at all). An implementation MAY reject mods without an `@in` declaration; it MUST NOT reject mods with an explicit empty declaration.

### 5.6 Unnamed caller args

Any value in the named-args bucket whose key does NOT appear in any declaration's name field MUST NOT reach the mod body. Conformant runtimes filter the caller's bucket against the schema's declared names and pass only the filtered subset to the mod. This is the core promise of `@in`: mods receive only what they declared.

## 6. Edge cases (enumerated)

### 6.1 Bare name, no qualifier

```
@in: note_id
```

Declaration: `note_id`, no qualifiers. Resolves from the named-args bucket key `note_id`; optional by default; no default value.

### 6.2 Required bare name

```
@in: note_id (required)
```

As §6.1 but required. Missing key surfaces as error per §5.4.

### 6.3 Default value

```
@in: limit (default: 10)
```

Declaration: `limit`, default `10` (integer). Missing key resolves via default.

### 6.4 Mixed declarations

```
@in: note_id (required), format (default: "html"), verbose (optional)
```

Three declarations; §4.1 shows the parse tree.

### 6.5 Quoted literal with comma

```
@in: tag (default: "one, two")
```

Default is the string `"one, two"` — the comma is inside quotes and does not split declarations.

### 6.6 Bareword default that looks like a number

```
@in: version (default: v42)
```

Bareword `v42` is a string `"v42"` (does not start with a digit, so the number rule does not match). Distinct from `(default: 42)`, which yields the integer `42`. Distinct from `(default: "42")`, which yields the string `"42"`.

### 6.7 Empty source, no default, optional

```
@in: admin (optional)
```

Declaration: `admin`, optional, no default. If missing, the key is simply absent from the invocation args; no error.

### 6.8 Required with default (nonsensical but not prohibited)

```
@in: id (required, default: "x")
```

Both qualifiers present. Spec-compliant parsers MUST accept the declaration. Resolution: `default` applies whenever the source is empty (§5.2); `required` has no effect because the default ensures a value always resolves. Effectively `default:` makes `required:` a no-op. Authors SHOULD avoid this combination; it's not banned to keep the parser simple.

### 6.9 Unknown qualifier

```
@in: id (frobnicated)
```

An unknown qualifier token is an error. The conformant parser MUST surface it (either at parse time with a parse error, or by rejecting the file). Silent acceptance of unknown qualifiers is forbidden — today's silent ignore is today's typo surviving into prod.

## 7. What `@in` is NOT

- **Not a CTN format.** CTN (see CTN-SPEC) is the block wire format. `@in` is a mod-source-file header.
- **Not a place-side grammar.** PLACE-SPEC's `@args` and per-line args (PLACE-SPEC §5.1, §8.2) bind URL parts and place-author intent into a caller-side args bucket. `@in` consumes that bucket. Different specs, different sides of the call.
- **Not a source-of-values grammar.** `@in` declares names, not their origins. URL positions, query strings, POST bodies, CLI argv positions, MCP tool-call args — all live in the caller's scope. A mod author who feels the need to encode "this arg comes from URL position N" in `@in` is trying to make the mod realm-aware, which the briefing bans.
- **Not a type system.** `@in` declares presence and default, not types. Values are whatever the caller supplied. A mod that wants a typed value coerces internally or uses a typed `default:` literal.
- **Not a dependency-injection container.** No auto-wiring, no reflection, no hidden providers. The caller explicitly supplies args; the runtime validates; the mod consumes.
- **Not documentation only.** A conformant runtime MUST enforce the schema. An implementation that parses `@in` but does not filter and validate caller args is non-conformant.

## 8. Conformance requirements

An implementation is **conformant** if:

1. It recognizes `@in:` lines per §3 within the file-head scan range.
2. It parses the payload per §4.
3. It applies the resolution semantics per §5 on every mod invocation.
4. It filters the caller's named-args bucket so that unnamed keys never reach the mod body (§5.6).
5. It enforces `required` per §5.4 and applies `default:` per §5.2.
6. It rejects unknown qualifiers per §6.9.
7. It treats the three empty-value cases per §5.3 as absent and no others.

## 9. Versioning

This is IN version 1.1.

A version bump is a deliberate, breaking change to the grammar or resolution semantics. It requires:

- A named motivation documented in `direction.md`.
- Updated conformance fixtures, if a conformance suite exists.
- All supported implementations updated in lockstep.

Additive changes that do not alter parse output for any existing declaration (documentation, clarifying examples, new edge-case callouts) do not require a version bump.

**Revision trail.**

- *1.0 (2026-04-29).* Initial spec. Lifted from PHP-only practice into a portable contract; closes the "@in is what we do but not what we spec" gap. Grammar (§4) and resolution semantics (§5) were stable at the impl level since 4.4.0.
- *1.1 (2026-05-26).* §5.4 clarification — enforcement is a Core responsibility, run once at the Core's invocation boundary. Strictly additive: the grammar is unchanged; resolution semantics §5.1–§5.3 and §5.5–§5.6 are unchanged. What lands at 1.1 is the explicit statement that Stacker-level pre-filtering plus Core-level re-filtering (the PHP reference's pre-5.1 shape) is non-conformant. The PHP reference at framework 5.1 collapsed the duplicated enforcement path; this revision documents the conformance rule the impl now realizes.

## 10. Known limitations (for a future 1.1 or 2.0)

- **No forward reference between declarations.** A declaration cannot refer to or depend on the resolution of a previous declaration in the same header. Declarations are independent.
- **No type annotations.** `@in` does not declare expected types. Typing remains the mod's internal concern.
- **No per-declaration validation beyond `required`.** There is no way to declare "this arg must match regex X" or "this arg must be one of {foo, bar}" inline. If a mod needs stronger validation, it does so in its body.
- **No source syntax ever** (decision, not limitation). See §4.3 and `direction.md`.

## 11. Implementation pointers

- PHP reference implementation: see `IN-in-PHP.md`.
- Go reference implementation: see `IN-in-Go.md` (stub pending real code).

---

*`@in` is the mod's declared input contract. Names and qualifiers only. Where each name's value came from is the caller's concern, not the mod's.*
