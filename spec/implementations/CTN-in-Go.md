# CTN-in-Go — Go Implementation Guide for CTN

**Status:** Informative. This document shows how to implement `CTN-SPEC.md` conformantly in Go. **`CTN-SPEC.md` is authority.** If this doc conflicts with the spec, the spec wins.

**Dated claims.** Any claim about a specific Go version, library version, or runtime behavior below is dated so a future reader can see when the claim was made and verify it's still current.

---

## Target environment

- **Go:** 1.21+ recommended (as of 2026-04-21).
- **YAML library:** `gopkg.in/yaml.v3`. Core-schema decoding into `interface{}` produces native Go types — `string`, `bool`, `int`, `float64`, `nil`, `[]interface{}`, `map[string]interface{}` — directly aligned with `CTN-SPEC.md` §8.2.

## Recommended implementation pattern

- CTN parser as a pure function: `func Parse(input []byte) []Block` where `Block` is a struct with `Type string`, `Fields interface{}`, `Body string`.
- Single-pass scan: find lines starting with `CTN:`, segment, YAML-parse each frontmatter region, assign body bytes.
- Line-ending normalization before framing: `\r\n` / `\r` → `\n`.
- YAML null-document result MUST normalize to `map[string]interface{}{}` per spec §4.2.3.
- Empty input → empty block list per spec §6.1.

## Reference implementation notes (containerist-go)

**Location:** `containerist-go/`. As of 2026-04-21 this repo contains actively developed Go code (`core.go`, `block.go`, `block_test.go`, `conformance_test.go`, `stackers/`, `modules/`, `cmd/`). The initial skeleton was empty on 2026-04-20; real parsing and dispatch code arrived between then and 2026-04-21. Check repo state before assuming anything specific about what's implemented.

**Conformance target:** the fixture suite at `containerist/conformance/ctn-fixtures/`. Any implementation that passes the fixtures is conformant with respect to the enumerated edge cases; the spec's §11 requirements cover the broader conformance surface (line-ending normalization, body byte-preservation, error-sentinel semantics).

## Intended Go idioms (from `go-port/decisions.md`)

- `*Containerist` passed as explicit first argument to every mod. Fixes the PHP `$C`-in-scope-via-`extract()` concession (mod signatures become legible at point of read; no hidden dependency).
- Mod args passed via typed structs, with code-generated dispatch shims.
- CTN-producing mods return `string`. HTML (terminal) mods write to `io.Writer`.
- Explicit `RegisterMod(name, fn)` calls from `main.go`. No `init()`-side-effect registration.
- YAML parsing via `gopkg.in/yaml.v3` into `interface{}` trees; no post-processing layer to coerce scalars.

These are convention targets; see actual code in the `containerist-go` repo for the current state of adoption.

## Historical Go implementations

None prior to `containerist-go`. That repo previously held a byte-identical copy of the PHP implementation (emptied 2026-04-20 to clear the name for the Go port; the PHP tree moved to `konnexus.net/` and stayed there).

## Conformance status (as of 2026-04-21)

`containerist-go` is under active development. The user-visible ls output as of 2026-04-21 shows `core.go`, `block.go`, `block_test.go`, `conformance_test.go`, `stackers/`, `modules/`, `cmd/`, `ctnr`, `build/`, `skin/`, `stacks/`. The conformance-test surface exists in file form; current pass/fail state against `conformance/ctn-fixtures/` is out of scope of this document — run the tests to find out.

Predicate on readiness: the spec's `conformance/ctn-fixtures/` population is the gating step. Once populated, `go test ./...` in the Go port should enforce conformance on every commit.

## `@in` parser (Go)

See `IN-in-Go.md` for the Go-specific implementation of `IN-SPEC.md`. Likely shape (per `go-port/decisions.md`): code-generated shim per mod, typed struct for args, schema derived from struct tags or a companion declaration, rather than runtime parsing of comment-embedded headers. The *semantics* match `IN-SPEC.md`; the *carrier* is Go-native.

## Stack parser (Go) / future Place parser

See `archive/4.x-stack-format/STACK-in-Go.md` for the Go-specific implementation of `STACK-SPEC.md` (archived at 5.0). Stack files were portable data across PHP and Go at 4.x; the parser differs per language. At 5.0+ the canonical format is `PLACE-SPEC.md` — when the Go port migrates, a `PLACE-in-Go.md` sidecar should be written, modeled on `PLACE-in-PHP.md`. Places are also byte-portable across language implementations.

---

*This document evolves with Go implementation state. Date any claim; update or remove when stale. CTN-SPEC.md is the target; this file explains how to hit it in Go.*
