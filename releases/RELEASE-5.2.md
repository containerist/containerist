# Containerist 5.2 — the CTN-waist hourglass

*Released 2026-05-27. PHP reference: `containerist-php` 5.2.0. Specs at this release: ACTS-SPEC 1.3 (draft), CTN-SPEC / PLACE-SPEC 1.1 / IN-SPEC 1.1 / FEDERATION-SPEC unchanged in grammar. Wire format (CTN, PLACE, IN, ACTS, FEDERATION) is byte-identical to 5.1 — every place, skin, CTN block, and `@in` declaration works unchanged. This is a behavior-preserving internal re-architecture: same URLs, same suffixes, same rendered bytes.*

5.2 re-architects the realm/render layer around one principle: **CTN is the architectural waist.** Above it, one realm-free, format-free **Core** produces all CTN. Below it, realm-free **renderers** turn CTN into output. At both ends, thin per-realm **adapters** do I/O. The 5.1 Stacker classes are retired.

---

## What changed in one paragraph

The 5.1 `PlaceStacker` / `CtnStacker` / `CliStacker` classes are gone. Their logic redistributes into three layers: **Core** (`core/containerist.php` — `id + args → CTN`, composes containers *and* places, realm-free + format-free), **renderers** (`core/renderers/` — CTN → realm-neutral response; html / raw / source; runs the acts), and **adapters** (`core/adapters/web/`, `core/adapters/cli/` — receive the request, pick the renderer by URL suffix, call Core, perform the response). The flow is `request → adapter → core → renderer → adapter → response`. Acts are the renderer's realm-neutral block-handlers — they *describe* effects; the adapter *performs* them. No behavior changes: this is extraction and renaming of structure that already lived inside the 5.1 Stacker (`dispatch_acts()` → renderer, `emit_response()` → adapter).

---

## Upgrade

1. **Sync `core/` from the reference.** The class/dir layout moved; instances pick it up by re-syncing.
   ```sh
   rsync -a --delete --exclude=.DS_Store ~/sites/containerist-php/core/ ./core/
   ```
2. **Re-point the entry points** from the reference's canonical versions: `index.php` instantiates `WebAdapter` (was `PlaceStacker`/`CtnStacker`); `cli.php` / `ctnr` instantiates `CliAdapter` (was `CliStacker`). Keep any instance-specific wrapping (auth gate, URL rewrites) around the framework routing.
3. **Fix any instance code referencing `core/stackers/` paths** → `core/adapters/` (web/cli) or `core/renderers/`.

No data migration. No place/skin/mod changes.

---

## What's the same

- **Wire format** — CTN, PLACE, IN, ACTS, FEDERATION byte-identical to 5.1.
- **Acts dispatch contract** — ACTS-SPEC §4 unchanged (one file per block type, block-order, redirect short-circuit, filesystem-presence = availability). The seven core acts are unchanged; they just live in the renderer now.
- **Mods, places, skins, federation** — unchanged.
- **CLI** — `./ctnr <mod> key=val` and the exit-code contract (ACTS-SPEC §7.2) work identically.
- **Deployment shapes** — full vs headless unchanged; headless just means "no place renderer resolves."

---

## Behavior changes

**None.** 5.2 is a rename + restructure. Every existing URL, suffix, and rendered byte is identical. The regression gate is the existing smokes (reference CLI 21/21, HTTP 36/36, instance smokes) staying green through the extraction.

The only code-visible change is for instances that referenced framework internals by name: `PlaceStacker`/`CtnStacker` → `WebAdapter`, `CliStacker` → `CliAdapter`, `core/stackers/` → `core/adapters/` + `core/renderers/`. Vestigial `stack_*` symbols that meant *place* are renamed to `place_*` (`find_stack_id` → `find_place_id`, `$request->stack_id` → `$request->place_id`, the `stack_parse_*` ref-parser functions → `place_parse_*`).

---

## Spec impact

- **ACTS-SPEC** — 1.2 (draft) → 1.3 (draft). Acts re-homed from "the Stacker" to "the renderer"; realm I/O attributed to "the adapter"; the prior "acts are realm code" framing corrected to realm-neutral (acts describe effects, the adapter performs them). The §4 dispatch contract and §5 core acts are unchanged in meaning — a 1.2-conformant implementation is automatically 1.3-conformant.
- **`containerist.md`** — 5.1.0 / doc 3.1 → 5.2.0 / doc 3.2. Vocabulary, file-layout, Pillar 8, entry-point pattern, acts, the suffix → renderer map, deployment shapes, and arg-lifecycle reframed to Core / renderer / adapter.
- **PLACE-SPEC, IN-SPEC, FEDERATION-SPEC** — terminology relabel only (Stacker → adapter / renderer / implementation by role). No grammar change, no version bump.
- **CTN-SPEC** — unchanged.

The `.text` renderer (terminal-readable output) is reserved in the suffix map but **deferred** — the hourglass makes it a small drop-in (`core/renderers/text/`); it is not part of 5.2.

---

## Compatibility posture

Per the wire-format-governed versioning rule (ACTS-SPEC §10, as applied at 5.0 and 5.1): the CTN wire format and the ACTS §4 dispatch contract are both unchanged, so this is a **minor** release despite the large internal/API restructure. PHP-reference class names and file layout change (API-breaking for code that named the internals), but that has never forced a major before. Federation-boundary compatibility with 5.0/5.1 instances is unaffected.

---

*Containerist 5.2 — CTN is the waist. Above it, one engine makes CTN. Below it, renderers turn CTN into output. The adapter is the realm edge. No Stacker, no Placer, no Lifter.*
