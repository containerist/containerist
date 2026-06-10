# IN-in-Go — Go Implementation Guide for `@in`

**Status:** Informative. This document shows how to implement `IN-SPEC.md` conformantly in Go. **`IN-SPEC.md` is authority.** If this doc conflicts with the spec, the spec wins.

**Dated claims.** Any claim about a specific Go version, library version, or runtime behavior below is dated so a future reader can see when the claim was made and verify it's still current.

---

## Target environment

- **Go:** 1.21+ recommended (as of 2026-04-21).

## Carrier syntax

The intended Go carrier for `@in` (per `go-port/decisions.md`) is **not** comment-parsed at runtime. It is either:

1. A typed Go struct per mod, with struct-tag annotations declaring required/default — for example:
   ```go
   type BlogracerNoteArgs struct {
       NoteID string `ctnin:"required"`
       Limit  int    `ctnin:"default=10"`
       Admin  bool   `ctnin:"optional"`
   }
   ```
   Dispatch shim code is generated from these structs (or reflected at startup).

2. A companion declaration file (e.g. `blogracer-note.in.yaml` or equivalent) that mirrors the PHP `@in:` comment payload but is Go-idiomatic.

Either carrier is acceptable as long as the *semantics* match `IN-SPEC.md`: the resolver filters named keys, applies defaults, enforces required, rejects unknown qualifiers.

## Reference implementation

As of 2026-04-21 the Go port at `containerist-go/` is under active development. The `modules/` directory exists; actual mod structure and `@in`-equivalent carrier form should be inspected in-repo. This guide will update to point at specific files once the pattern stabilizes.

## Recommended semantics

- **Schema type:**
  ```go
  type InSchema map[string]InDecl

  type InDecl struct {
      Required bool
      Default  interface{}  // nil if no default
  }
  ```
- **Resolver:** pure function over `(args map[string]interface{}, schema InSchema) (filtered map[string]interface{}, err error)`. Applies `IN-SPEC.md` §5.2 – §5.4.
- **Empty-value rule:** match `IN-SPEC.md` §5.3 — key absent, value is `nil`, or value is empty string `""`. Other zero-values (`0`, `false`) do NOT count as empty.
- **Error:** return a typed error (e.g. `type MissingInputError string`). The mod dispatcher surfaces it per its own convention — HTTP 400, CTN error block, CLI stderr, etc.

## Idiom notes

- **`*Containerist` as explicit arg.** Per `go-port/decisions.md`, Go mods take `*Containerist` as the first parameter. This differs from PHP's `$C`-in-`extract`-scope concession. No change to `IN-SPEC.md`: `@in` still declares only named user inputs; `*Containerist` is the runtime handle and is passed structurally, not declared in `@in`.
- **Code generation vs reflection.** Either is acceptable. Code generation (via `go generate`) produces smaller runtime overhead and catches errors at build time; reflection is simpler to bootstrap. Pick one per site preference.

## What this spec version does NOT require (and the Go implementation need not add)

- No source references (`$N`, `$name`, `{{1}}`, etc.) in `@in` declarations. Mods declare names only. Where each name's value came from is the caller's concern. See `IN-SPEC.md` §4.3 and `direction.md` § Decisions not to do.
- No forward references between declarations.

## Testing

Table-driven tests per `IN-SPEC.md` §6 edge cases. Integration tests through a real mod invocation with both valid and invalid args.

## Not yet

As of 2026-04-21, no specific Go `@in` implementation is documented in this guide. When `containerist-go`'s `@in` handling stabilizes, update with file paths, struct-tag grammar, and observed conformance status.

---

*This document evolves with Go implementation state. Date any claim; update or remove when stale. IN-SPEC.md is the target; this file explains how to hit it in Go.*
