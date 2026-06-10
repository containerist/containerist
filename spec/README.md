# spec/ — The canonical formats

These documents are the **authority**. No single implementation owns the formats; they conform to what is written here. When an implementation and a spec disagree, the spec wins (or the spec is revised deliberately, in writing).

## The specs

| Spec | Defines |
|---|---|
| **[CTN-SPEC.md](CTN-SPEC.md)** | CTN — the portable container structure format (YAML frontmatter + body) |
| **[IN-SPEC.md](IN-SPEC.md)** | `@in` — how a container declares the inputs it needs |
| **[PLACE-SPEC.md](PLACE-SPEC.md)** | the place format — URL → surface resolution, `@args` binding, `@federation` directives |
| **[ACTS-SPEC.md](ACTS-SPEC.md)** | sessions, auth facts, and the POST → redirect → GET (PRG) lifecycle |
| **[FEDERATION-SPEC.md](FEDERATION-SPEC.md)** | cross-origin containers — transport, trust, and error model |
| **[WIRE-SPEC.md](WIRE-SPEC.md)** | the optional CTN-over-HTTP adapter |

## Implementation guides

**[implementations/](implementations/)** holds per-language sidecars — how each spec is realized in PHP, TypeScript, and Go. These are *informative*: they describe an implementation's current shape and yield to the specs above on any conflict.

## Conformance

**[../conformance/](../conformance/)** contains language-agnostic input/output fixtures for CTN. An implementation that reproduces the expected output for every fixture is conformant with respect to the enumerated edge cases; each spec's own requirements section covers the broader surface.

## Version history

The retired 4.x stack format lives in **[../archive/](../archive/)** for anyone porting forward. Release notes and migration guides are in **[../releases/](../releases/)**; the version arc is in **[../CHANGELOG.md](../CHANGELOG.md)**.
