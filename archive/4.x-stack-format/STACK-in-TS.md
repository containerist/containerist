# STACK-in-TS — TypeScript Implementation Guide for Stack Files *(archived 2026-05-22)*

> **ARCHIVED.** This document describes the 4.x stack-file format. Containerist 5.0 retired stacks in favor of places (see `PLACE-SPEC.md`). The TS port (`containerist-ts`) is at 4.x as of this archive date — until it catches up to 5.0, this sidecar remains the operative guide for that port. When the TS port migrates to 5.0, an analogous `PLACE-in-TS.md` should be written (modeled on `PLACE-in-PHP.md`).

**Status:** Informative for 4.x. `STACK-SPEC.md` (archived alongside) is authority for the 4.x format; on conflict the spec wins.

---

## Target environment

- **Node** 18+.
- **Shared text format.** Stack files are plain text, identical on disk across PHP, Go, TS. No implementation-specific encoding.

## Two-layer implementation

Pure functions — no filesystem I/O inside the core logic. The Web Stacker injects the I/O boundary.

### Layer 1 — URL resolution (§4)

```ts
export function parseRequest(
  urlPath: string,
  stackExists: (stackId: string) => boolean,
): ParsedRequest
```

`stackExists` is the DI seam: production wraps `fs.existsSync`; tests wrap a `Set`.

**Wildcard priority (§4.2–§4.3):** enumerate all `2^n` candidate masks, sort by `(popcount asc, mask desc)`, probe in order, first existing wins. Mask-descending encodes the rightward-bias rule: for `/foo/bar`, `foo--*` (mask `0b10`=2) beats `*--bar` (mask `0b01`=1).

**Suffix classification (§4.1.5):**
- Dispatch (`ctn`, `raw`, `stack`, `stackctn`, `htmx`, `trace`) → preserved in `ParsedRequest.suffix`.
- Alias-default (`html`) → stripped, `suffix=''`, equivalent to a no-suffix URL.
- Unknown → `invalidSuffix=true`, `stackId=null`.

### Layer 2 — Stack text parsing (§5–§7)

```ts
export function parseStackText(text: string, urlParts: string[]): StackFile

export interface StackRef {
  prefix: string;                   // '' or 'htmx'
  name: string;
  args: Record<string, string>;     // per-line args, resolved (unbound refs omitted)
}
export interface StackFile {
  args: Record<string, string>;     // resolved @args + numeric URL parts
  refs: StackRef[];
}
```

**Pre-processing (§6.3):** `@`-prefixed keys at line start are YAML-reserved. The impl regex-quotes matched lines (key AND value) before parsing so the `{{…}}` mini-grammar inside `@args` values doesn't collide with YAML flow syntax.

**Body-line grammar (§7.2):** `[prefix:] name[?k1=v1&k2=v2...]`. The `:` is a prefix delimiter only if it appears before any `?`.

**Per-line value resolution (§7.3.1):** `{{N}}` / `{{name}}` resolve against `{...numeric, ...@args}`. Unbound refs → key omitted (no empty-string substitution). Quoted literals support `\"`, `\'`, `\\`. Barewords pass through. Repeated keys: last wins (native `Object.assign`).

## Composition (§8)

For each `StackRef` in order:
- `''` prefix → `Core.ctn(name, invocationArgs)`. Query string + session facts are merged upstream of this call (see arg lifecycle below).
- `'htmx'` prefix → emit `CTN: htmx\nsrc: /<name>.htmx?<qs>\nname: <name>\n` directly.
- Unknown prefix → emit `CTN: error` block with code 500. Does NOT silently drop (§7.2).

Output is a concatenated CTN string → parsed into blocks → handed to the acts layer (`ACTS-in-TS.md`).

## Arg lifecycle (per ACTS-SPEC §6)

Later sources win on key collision:

1. URL numeric parts (`{{1}}`, `{{2}}`, …) — from `parseRequest`.
2. Stack `@args` — from `parseStackText`.
3. Stack per-line args — from the matched `StackRef`.
4. **Session-resolved facts** — from the decrypted session cookie (see `ACTS-in-TS.md`).
5. Query string (URL query params).
6. POST body (future).

Positions 1–3 owned here. Position 4 owned by `ACTS-in-TS.md`. Positions 5–6 are the Stacker's realm adapter.

## File locations

- `src/stackers/request.ts` — URL resolution.
- `src/stack.ts` — stack text parsing.
- `src/stackers/web.ts` — composition + arg lifecycle assembly.
- Tests: `test/request.test.ts` (17 cases, §4), `test/stack.test.ts` (21 cases, §5–§7).

Both test files exercise the pure parsers directly — no tmp files, no fs mocks.

## Idioms and non-requirements

- DI via callback, not class inheritance. `parseRequest(urlPath, stackExists)` is a plain function.
- Named interfaces for shared shapes (`ParsedRequest`, `StackRef`, `StackFile`).
- Regex-driven grammar. The grammars are small enough that hand-written regexes beat generator output for readability.
- No filesystem scan of the stacks dir — the wildcard resolver probes via the injected callback. Pre-listing caches can live in the caller.
- No persistent cache of parsed stacks (parse cost is microseconds).
- No precompiled router — `2^n` enumeration bounded by URL depth; for `n ≤ 4` that's ≤16 candidates.

## Conformance

Conformant with STACK-SPEC 2.0: `{{N}}`/`{{name}}` grammar, per-line args, wildcard priority with rightward tiebreak, alias-default suffix (`.html`), dispatch suffixes, `@args` YAML-quoting preprocessing. Session-facts injection at position 4 is wired in `dispatchRef`, `emitRaw`, `emitStackCtn`, `emitHtmxMod`.

## Related

- `CTN-in-TS.md` — parser the stack parser depends on.
- `IN-in-TS.md` — per-mod input resolution applied to the args bucket this spec produces.
- `ACTS-in-TS.md` — acts layer receiving the composed CTN stream.
- `STACK-in-PHP.md`, `STACK-in-Go.md` — companion language guides.
