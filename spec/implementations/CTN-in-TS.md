# CTN-in-TS — TypeScript Implementation Guide for CTN

**Status:** Informative. `CTN-SPEC.md` is authority; on conflict the spec wins.

---

## Target environment

- **Node** 18+.
- **Runtime loader:** `tsx` (v4+) — used for both `./ctnr` and the test runner.
- **YAML library:** `yaml` npm package (v2+). Core-schema output aligned with CTN-SPEC §8.2 — native `string` / `number` / `boolean` / `null` / `object` / `array`.

## Implementation

Pure function at `src/block.ts`:

```ts
export interface Block {
  type: string;
  fields: unknown;          // typically Record<string, unknown>; see below
  body: string;
}

export function parseCtn(input: string): Block[]
export function asMap(fields: unknown): Record<string, unknown>    // narrowing helper
```

Single-pass scan: find `/^CTN:[ \t]*(\S+)[ \t]*$/gm`, segment between headers, hand each frontmatter to `YAML.parse`, assign body bytes.

**`fields: unknown`**, not `Record<string, unknown>`. Per spec §5, `fields` is a `YAMLValue` (mapping, sequence, or scalar) — in TS that's `unknown`. Consumers that look up keys use `asMap(fields)` to narrow; non-mappings resolve to `{}` at the narrow call, preserving the raw value on `block.fields` itself for callers who want to handle sequences directly.

**Line endings.** Normalized to `\n` before framing: `text.replace(/\r\n?/g, '\n')`.

**Separator.** `/^[ \t]*---[ \t]*$/m` — leading/trailing whitespace tolerated (§6.13).

**Body.** Trailing `\n` stripped per §4.2.2 (§5). Leading + interior preserved.

**Malformed YAML.** Produces a sentinel block per §4.5: `{ type: headerType, fields: { _yaml_error: "<message>" }, body: "" }`. Parsing continues. `_yaml_error` is reserved — authors MUST NOT use it as a field name.

**Null / empty frontmatter.** Normalizes to `{}` (§4.2.3).

**Non-mapping top-level.** Preserved as the raw YAML value (sequence or scalar). No throw, no coercion.

## Conformance

- Framing ✓  · Core schema ✓  · Line-ending normalization ✓  · `_yaml_error` sentinel ✓  · Non-mapping top-level passthrough ✓  · Trailing-newline trim ✓  · Separator whitespace tolerance ✓
- Test file: `test/block.test.ts` — 17 cases, each citing the spec section it covers (§4.2.2, §4.2.3, §4.5, §6.1, §6.13, …).
- Fixture suite `/containerist/conformance/ctn-fixtures/` is empty as of 2026-04-23; `parseCtn` should run against each once populated.

## Non-requirements

- No runtime type coercion — `yaml` pkg default is sufficient.
- No frontmatter schema — that's `IN-SPEC`'s job.
- No AST node classes — blocks are plain objects.
- No rejection at the framing layer — every byte stream produces a defined block list (§11).

## Always-CTN invocation (5.1+)

`Core.ctn(id, args)` never throws; failures become `CTN: error` blocks
per CTN-SPEC §4.4. Codes: 400 (schema `MissingInputError`), 404 (unknown
id), 500 (runtime / read failure / ambiguous stem). `Core.mod()` is
internal — adapters and modules call `ctn()` exclusively. To detect an
error result without parsing, callers use `s.startsWith('CTN: error')`
(e.g. `modules/pages/page-display.ctn.ts`).

## Related

- `IN-in-TS.md` — mod input contract (`ModSchema` object, not a comment header).
- `PLACE-in-TS.md` — place parsing + URL resolution.
- `ACTS-in-TS.md` — block dispatch at the html renderer layer.
- `CTN-in-PHP.md` — companion guide.
