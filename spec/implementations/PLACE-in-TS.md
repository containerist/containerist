# PLACE-in-TS — TypeScript Implementation Guide for Places

**Status:** Informative. `PLACE-SPEC.md` is authority; on conflict the spec wins.

This sidecar documents the TS-specific conventions for the place format introduced at Containerist 5.0. It supersedes the archived 4.x `STACK-in-TS.md` — 5.0 instances no longer ship the `.txt` stack format the older sidecar described. Reference impl is `containerist-ts` 5.2.0+.

---

## Target environment

- **Node** 18+. Test runner `node:test` via `tsx` (v4+) — same as the rest of the TS port.
- **YAML library:** `yaml` npm package (v2+). YAML 1.2 strict — `off`/`on`/`yes`/`no` stay strings unless explicitly quoted, so the YAML 1.1 footguns documented in `PLACE-in-PHP.md` do NOT apply here. Plain `prg: off` parses to the string `"off"`, not boolean `false`.
- **HTTP layer:** Next.js 14 App Router. `app/[[...slug]]/route.ts` is a 15-line catch-all that calls `getWebAdapter().handle({url, cookieHeader})`. Static assets sit alongside in `public/`.

## File layout

Place files live in `places/` at the project root. Filename stem = place id (`places/whoami.place` → id `whoami`). Wildcard-priority resolution happens in `src/adapters/web/request.ts::findPlaceId`. Only files with the `.place` extension are matched.

```ts
const placesDir = path.join(root, 'places');
const placeExists = (id: string) => fs.existsSync(path.join(placesDir, `${id}.place`));
```

## The frontmatter-only canonical form

Same rule as PHP: **the canonical form has no `---` separator and no body section.** Every place-level key — `@args`, `containers`, `regions`, etc. — lives in the CTN frontmatter. The CTN parser's first-block `fields` mapping is the entire declaration.

```
CTN: place
@args: id = {{2}}
containers:
  - page-header
  - "article?id={{id}}"
  - page-footer
```

`block.fields.containers` is a YAML array of ref strings. `block.body` is empty. The TS impl reads `containers` only; multi-region `regions:` is a fast-follow (not yet wired through to acts dispatch).

## `@args` preprocessing

YAML 1.2 reserves `@` at line-start. The `yaml` package rejects unquoted `@args: …` as a parse error. Workaround at `src/place.ts::quoteAtKeys`:

```ts
function quoteAtKeys(text: string): string {
  return text.replace(/^(@\w+):[ \t]*(.*)$/gm, (_m, key, value) => {
    // quote both key and value
  });
}
```

Applied before handing the text to `parseCtn`. Authors writing place files don't need to quote `@args:` themselves — the preprocessing is invisible. This is the TS-side equivalent of the PHP sidecar's `federation_preprocess_at_federation_text` pattern.

## Parser

Pure function at `src/place.ts`:

```ts
export interface PlaceRef {
  prefix: string;                 // '' = plain; 'htmx' = deferred fetch
  name: string;                   // container name
  args: Record<string, string>;   // per-line args, resolved
}

export interface PlaceFile {
  args: Record<string, string>;   // @args + numeric URL parts
  refs: PlaceRef[];
}

export function parsePlaceText(text: string, urlParts: string[]): PlaceFile
```

Resolution per PLACE-SPEC §5 (`@args`) and §8 (per-line refs). `{{N}}` numeric refs, `{{name}}` named refs, quoted literals, and barewords are all handled by the shared `resolveValue` helper. Unbound refs omit the key (§5.1.2, §8.2.1).

## URL resolution

`src/adapters/web/request.ts::parseRequest` is pure: URL string + `placeExists` predicate → `{ parts, suffix, placeId, invalidSuffix }`. The mask-based wildcard scan (PLACE-SPEC §4.2–§4.4) orders candidates by ascending wildcard count, then by descending mask (rightward bias), and returns the first match against the predicate. The mask-bit-i = part-i convention is documented inline at `findPlaceId`.

## Conformance

- §3 frontmatter-only form ✓ · §4 URL resolution + priority ✓ · §5 `@args` ✓ · §8 per-line refs ✓
- Test files: `test/place.test.ts` (22 cases, parser), `test/request.test.ts` (16 cases, URL → place id), `test/integration-web.test.ts` (full pipeline)
- Multi-region (`regions:`) is NOT yet supported — fast-follow.

## Non-requirements

- No `.txt` fallback. 4.x stack files are not recognized.
- No `prg`/`auth`/`dispatcher` directive handling yet — the place loader reads `containers:` only. These directives are valid PLACE-SPEC keys but the TS port treats them as ignored fields for now (parsed but not dispatched).
- No `@federation`. Federation belongs in a follow-up port.

## Related

- `CTN-in-TS.md` — CTN parser (places ARE CTN docs).
- `ACTS-in-TS.md` — block dispatch after container refs are resolved.
- `PLACE-in-PHP.md` — companion guide with the full Spyc-era footgun catalog.
