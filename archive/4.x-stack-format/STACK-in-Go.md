# STACK-in-Go — Go Implementation Guide for STACK *(archived 2026-05-22)*

> **ARCHIVED.** This document describes the 4.x stack-file format. Containerist 5.0 retired stacks in favor of places (see `PLACE-SPEC.md`). The Go port (`containerist-go`) is at 4.x as of this archive date — until it catches up to 5.0, this sidecar remains the operative guide for that port. When the Go port migrates to 5.0, an analogous `PLACE-in-Go.md` should be written (modeled on `PLACE-in-PHP.md`).

**Status:** Informative for 4.x. `STACK-SPEC.md` (archived alongside) is authority for the 4.x format; on conflict the spec wins.

**Dated claims.** Any claim about a specific Go version, library version, or runtime behavior below is dated so a future reader can see when the claim was made and verify it's still current.

---

## Target environment

- **Go:** 1.21+ recommended (as of 2026-04-21).

## Reference implementation

As of 2026-04-21, `containerist-go` at `containerist-go/` contains active Go code including `stackers/` and `stacks/` directories. Check repo state for current file layout. The Go port picks up `STACK-SPEC.md` version 2.0 from day one — no `$N` / `$name` legacy to migrate from on the Go side (the repo was empty until 2026-04-20 and grew its first real code after that).

## Recommended Go patterns

- **URL resolution:** pure function `ResolveStack(parts []string, stacksDir string) (matchedPath string, ok bool)`. Given URL parts and the stacks root, walks the 2^n wildcard enumeration per `STACK-SPEC.md` §4.2 – §4.4.
  - Priority sort is deterministic; implement as: all candidates generated, sorted by `(wildcardCount, -bitmask)`, first-file-exists wins. A stat cache keyed on stacks-dir mtime is a reasonable optimization.
- **Suffix classification:** implement per `STACK-SPEC.md` §4.1.5. Three constant sets (`dispatchSuffixes`, `aliasDefaultSuffixes`, rest → 404). Dispatch suffixes never reach `ResolveStack`; alias-default suffixes are stripped before it.
- **Stack composition:** pure function that takes a parsed CTN document (per `CTN-SPEC.md`) and a URL-parts slice, resolves `@args`, iterates body lines with per-line args parsing per `STACK-SPEC.md` §7.3, produces a combined CTN byte slice.
- **Per-line args parsing:** use `net/url.ParseQuery` on the portion after `?`, then walk the result substituting `{{N}}` and `{{name}}` refs against the resolved-args map. Bareword rules and quoted literals need a small dedicated parser (query-string-shaped but allowing quoted values).

## Data types

Suggested shapes:

```go
type StackMatch struct {
    Path  string            // filesystem path to the matched .txt file
    Parts []string          // URL parts post-suffix-strip
    Suffix string           // raw suffix, empty if none (informational only)
}

type ResolvedArgs map[string]string

type ContainerRef struct {
    Prefix      string          // "" or "htmx" etc.
    Name        string
    PerLineArgs map[string]string
}
```

## Testing

A table-driven test suite against `STACK-SPEC.md`'s matching examples (§4.3, §4.4), `@args` grammar cases (§6.2), per-line args cases (§7.3.2, §9.7), and suffix-class cases (§9.9) is the natural conformance surface on the Go side. Integration tests can exercise `stackers/web/` end-to-end against a known `stacks/` fixture directory.

## Not yet

As of 2026-04-21, no Go code specific to STACK-SPEC is documented in this guide beyond the above patterns. When `containerist-go`'s stack parser stabilizes, update this doc with file paths and observed conformance status.

---

*This document evolves with Go implementation state. Date any claim; update or remove when stale. STACK-SPEC.md is the target; this file explains how to hit it in Go.*
