# IN-in-TS — TypeScript Implementation Guide for `@in`

**Status:** Informative. `IN-SPEC.md` is authority; on conflict the spec wins.

---

## Target environment

- **Node** 18+.
- **No runtime parser.** The TS carrier is native: no `@in:` comment header is parsed at runtime.

## Carrier syntax

Typed object exported alongside the mod's default function. The TS-native equivalent of PHP's `// @in:` comment header or Go's struct-tag path.

```ts
// modules/echo.ctn.ts
import type { Core } from '../src/core';
import type { ModSchema } from '../src/schema';

export const schema: ModSchema = {
  text: { required: true },
  loud: { default: false },
};

type Args = { text: string; loud: boolean };

export default function echoMod(args: Args, _C: Core): string {
  return `CTN: standard\n---\n${args.loud ? args.text.toUpperCase() : args.text}\n`;
}
```

A mod exports two names: `default` (the function) and `schema` (the `ModSchema` object). `modules/index.ts` wires both into the registry.

## Schema + resolver

```ts
export interface ModDecl {
  required?: boolean;
  default?: unknown;           // undefined = no default
}
export type ModSchema = Record<string, ModDecl>;

export function applySchema(
  args: Record<string, unknown>,
  schema: ModSchema,
  modName?: string,
): Record<string, unknown>
```

`applySchema` — pure, matches `IN-SPEC.md` §5.2–§5.4:
- Filters undeclared keys (§5.6).
- Applies `default` when the supplied value is empty (§5.3).
- Throws `MissingInputError` when a required declaration is unresolved (§5.4).

Declaration order is preserved via JS's object-key-insertion-order guarantee (ES2015+). Order matters for the CLI Adapter's positional-arg mapping.

## Empty-value rule (§5.3)

Empty iff: key absent · `undefined` · `null` · `""`. Note the TS-specific inclusion of `undefined` (no PHP equivalent). `0`, `false`, `"0"`, `"false"` are NOT empty.

## Unknown qualifiers (§6.9) — structural, not runtime

The `ModDecl` interface declares exactly `required` and `default`. Any other property is a TypeScript compile-time error under `--strict` (which the repo uses). There are no qualifier *tokens* to parse — qualifiers are typed object keys.

PHP parses strings and must reject unknown qualifiers at runtime. TS uses the type system; rejection is at compile time. Both satisfy §6.9's intent (no silent acceptance).

## Error shape

```ts
export class MissingInputError extends Error {
  constructor(public readonly inputName: string, public readonly modName?: string)
}
```

Surfaced per Adapter:
- **Web** (`.ctn`/`.htmx` direct dispatch): HTTP 400 + plain-text body naming the missing input.
- **Web** (normal pipeline): the Adapter catches at the composition boundary and emits a `CTN: error` block; the `error` act handles per ACTS-SPEC §5.3.
- **CLI**: stderr + exit 1 + `describeSchema()` reminder.

## Idioms and non-requirements

- `ModFn` at the framework level is `(args: any, C: Core) => string`. Per-mod `type Args = { ... }` narrows. The schema, not TS's type system, is the runtime contract. (`--strictFunctionTypes` would otherwise reject a heterogeneous mod registry.)
- `as const satisfies ModSchema` is available for authors who want compile-time-literal schemas; reference mods don't use it.
- No codegen, no build step beyond the existing TS compile. Fresh mod = create file + add to `modules/index.ts` + write `schema`.
- No source references in `@in` (per spec §4.3). No URL positions, no `{{N}}`, no `$N`.
- No forward references between declarations.
- No comment parsing at runtime.

## Conformance

Conformant with IN-SPEC 1.0 across §3–§6. §6.9 unknown-qualifier rejection is compile-time rather than runtime (see above).

Reference: `src/schema.ts` (~70 LOC). Tests: `test/schema.test.ts` — 20 cases, including all four corners of the §5.3 empty-value rule.

## Related

- `CTN-in-TS.md`, `PLACE-in-TS.md`, `ACTS-in-TS.md` — sibling TS guides.
- `IN-in-PHP.md`, `IN-in-Go.md` — companion language guides.
