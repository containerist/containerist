# Containerist 5.0 — Places replace stacks

*Released 2026-05-21. PHP reference implementation: `containerist-php` 5.0.0. Specs at this release: PLACE-SPEC 1.1, ACTS-SPEC 1.1, CTN-SPEC unchanged, IN-SPEC unchanged, FEDERATION-SPEC 2.0 unchanged, STACK-SPEC 2.0 (deprecated).*

5.0 is the first genuine breaking release since 4.0. The `.txt` stack format is retired; **places** take over as the canonical surface-declaration format. The Stacker concept and the eight pillars are unchanged. Every mod written for 4.x runs unchanged in 5.0 — what changed is the file format the Stackers read, the directory they read it from, and one runtime-behavior default for POSTs to those files.

Read this end-to-end before upgrading. Short and concrete.

---

## What changed in one paragraph

A **place** is a YAML declaration of a Containerist surface: which URL it matches, which containers compose it, and (new in 5.0) optional regions for AI-composed surfaces. A place file lives at `places/<pattern>.place`, replaces the 4.x `stacks/<pattern>.txt` stack file, and is **strictly more general** than a stack — a place with one `fixed` region containing a flat container list IS a stack, with no behavior difference. Multi-region surfaces (`fixed` + `constrained-fluid` + `open-fluid` modes), `dispatcher` selection (buffered default, streaming opt-in), and `prg: off` per-place opt-out for API endpoints are new capabilities the `.txt` format couldn't express. Authoring vocabulary tightens too: the word *place* replaces *stack* at the top level; *stack* survives as the lowercase term for one fixed region's ordered container list inside a place. The runtime engine remains the **Stacker**.

---

## Upgrade in three steps

### 1. Migrate `.txt` → `.place` (mechanical)

A converter ships at `core/tools/stack-to-place.php`:

```sh
php core/tools/stack-to-place.php stacks places
```

This walks `stacks/*.txt`, emits `places/<pattern>.place` for each, and reports `OK` or `FAIL` per file. Frontmatter (`@args`, `@federation`) carries over verbatim; body lines lift into a YAML `containers:` sequence inside the same CTN frontmatter (no `---` separator in the output — PLACE-SPEC 1.1 §3.1 spells out the canonical form). Quoting is automatic for refs containing `{`, `}`, `?`, or `:`. After verifying the output, retire the source directory:

```sh
rm -rf stacks
```

The 5.0 reference's `WebStacker` no longer reads `.txt` files. Sites still authoring stacks must complete the migration before upgrading.

### 2. Sync the new `core/` from the reference

```sh
rsync -a --delete --exclude=.DS_Store ~/sites/containerist-php/core/ ./core/
```

Or pull `containerist-php` from your source of truth and copy `core/` over. The reference is authoritative: never edit `core/` on a live instance.

### 3. Sweep instance code for `stacks/` references

Likely candidates: `tools/DEPLOY.md`, `tools/smoke.sh`, any mod that names its source path in comments, any skin template that mentions the source file. The exact set varies; a grep catches them:

```sh
grep -rn "stacks/\|\.stack\|\.stackctn\|STACKS_DIR" \
  --include="*.php" --include="*.html" --include="*.sh" --include="*.md" \
  --exclude-dir=core --exclude-dir=_pre-port-backup-* .
```

Replace `stacks/` → `places/`, `.stack` → `.place`, `.stackctn` → `.placectn`, `STACKS_DIR` → `PLACES_DIR`. Comments and docs are the bulk of it.

### 4. (Optional, but only if you ship POST APIs) Declare `prg: off` on API endpoints

5.0 introduces auto-PRG (POST → 303 → GET) for every POST to a place URL. This is correct for browser-driven form submissions but wrong for JSON / multipart APIs that return synchronous response bodies. Two bypasses apply automatically:

- **HTMX requests** (carrying `HX-Request: true`) skip PRG.
- **Bearer-authed requests** (carrying `Authorization: Bearer …`) skip PRG.

Anything else — session-authed JSON APIs, file uploads — declares `prg: off` in the place frontmatter:

```yaml
CTN: place
prg: off
containers:
  - my-api-mod
```

If you have no place-routed POST APIs (most sites don't), you have nothing to do here. If you do, `prg: off` is the one-line opt-out.

---

## What's the same

The intent of this list is reassurance, not exhaustiveness.

- **The eight pillars.** Pillar 2 ("Pages = flat stacks") is restated as "Pages = places" with no semantic shift — a place with one fixed region IS a flat stack of containers. Pillar 4 ("URL = stack variable scope") becomes "URL = place variable scope" with identical mechanics. The other six pillars are word-for-word.
- **Mod authoring.** `@in` declarations, CTN block emission, skin pairs, the realm-free Core / realm-locked Stacker boundary — every rule from 4.x carries forward unchanged. A mod from a 4.x site is a 5.0 mod.
- **Skin format.** `.html` + `.css` per CTN type, applied by the `skin` act. (And — clarified at 5.0 in `docs/containerist.md` — an optional `.js` third member when the block needs client-side interaction. Already legal in 4.x; the convention is just spelled out now.)
- **The acts layer.** All seven core acts (`skin`, `redirect`, `error`, `content-type`, `session`, `flash`, `title`) work identically. The acts dispatch contract in ACTS-SPEC §4 is unchanged.
- **Federation.** `@federation:` directives carry over to place frontmatter byte-for-byte. The federation spec is at 2.0; no 5.0 change.
- **CLI invocation.** `./ctnr <mod> key=val` and in-process `$C->mod()` calls are unchanged.
- **The Stacker concept.** What the Stacker reads changed; how it composes is the same. The original Stacker insight — uniform invocation across HTTP, CLI, and in-process — survives intact.

---

## What's new (capabilities, not migrations)

These are net-new, optional, additive on top of the migration. None are required for upgrading.

- **Multi-region surfaces.** A place can declare named regions (`nav`, `tools`, `main`, …), each with its own composition mode and container list. `fixed` mode is the 4.x-equivalent shape; `constrained-fluid` lets an LLM pick from a declared accept-list (`candidates:`); `open-fluid` lets an LLM pick from the full mod library. PLACE-SPEC §6, §7.
- **Dispatcher variants.** Places select between `dispatcher: buffered` (default, 4.x semantics) and `dispatcher: streaming` (incremental flushes; long-running containers stream to the wire as they produce). Mods MUST NOT branch on which dispatcher invoked them; the contract is symmetric. PLACE-SPEC §9.
- **`prg: off` opt-out.** Per-place override for the auto-PRG default. See step 4 above.
- **Auto-PRG default.** Every non-HTMX, non-Bearer POST to a place URL is wrapped in PRG (POST → 303 → GET). Browser form-submits now have refresh-resubmit immunity by default. ACTS-SPEC §7.1.

---

## Where to read more

- **Mechanical migration table** with field-by-field mapping: `PLACE-SPEC.md` §13.
- **PLACE-SPEC 1.1 in full**: the canonical surface format. `PLACE-SPEC.md`.
- **ACTS-SPEC 1.1**: §7.1 covers auto-PRG, both bypasses, and the conformance clause naming it as Web-Stacker policy (not ACTS contract).
- **Change list, terse**: `CHANGELOG.md` 5.0.0 entry.
- **The full 5.0 cycle's story** (trigger, validating prototype, all six steps of the evolve workflow, lessons): `logs/2026-05-21-5-0-places.md`. Recommended reading for anyone porting another implementation (TS, Go, future) — the lessons explain why each piece landed the way it did.
- **`archive/4.x-stack-format/STACK-SPEC.md`**: archived 2026-05-21. The deprecated 4.x stack-file grammar, retained for migration reference and any language port (TS, Go) still at 4.x. New sites SHOULD NOT author against it.

---

## Compatibility posture

5.0 is a **clean cut**. The 5.0 reference does not read `.txt` stack files; it does not accept `.stack` / `.stackctn` URL suffixes as aliases for `.place` / `.placectn`. Instances on 4.x continue to run 4.x — that contract is preserved by not auto-upgrading the framework. Instances on 5.0 must have migrated.

Per the established framework versioning rule (`ACTS-SPEC.md` §10): the next breaking change earns a 6.0. Inside the 5.x line, additions land via spec minor bumps (PLACE-SPEC 1.1 → 1.2, etc.) and framework patches (5.0.1, 5.0.2). The 0.x permissiveness on PLACE-SPEC has ended at promotion.

---

## A word on instance-first prototyping

The validating implementation for PLACE-SPEC's 0.1 → 1.0 promotion was an instance (konnexus-ai), not the reference. The format was shaped against actual surfaces before being canonicalized; the reference caught up afterwards. This inverted the usual reference-leads discipline deliberately and only once, for the spec's 0.x → 1.0 promotion. The journal entry's "lesson 3" (instance-first is the right shape only when the spec is genuinely 0.x and needs validation before normative promotion) is the rule that earned its keep here.

If you're running multiple Containerist instances and considering changes that might land instance-first, read that journal entry first. The reference-leads-instances-follow rule still holds for everyday work.

---

*Containerist 5.0. Same Stacker, new format.*
