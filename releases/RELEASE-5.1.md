# Containerist 5.1 — Core simplification + headless deployment

*Released 2026-05-26. PHP reference implementation: `containerist-php` 5.1.0. Specs at this release: ACTS-SPEC 1.2 (draft), IN-SPEC 1.1, CTN-SPEC unchanged, PLACE-SPEC 1.1 unchanged, FEDERATION-SPEC 2.0 unchanged. Wire format (CTN, PLACE, IN, ACTS, FEDERATION) is byte-identical to 5.0; doc-level updates to `containerist.md`, the `*-in-PHP.md` sidecars, IN-SPEC §5.4, and ACTS-SPEC §5.3 + §7.2 reflect the new Core API shape and the CLI Stacker exit-code contract.*

5.1 is a Core-API consolidation plus a new deployment shape. The wire format is unchanged — every place file, every skin set, every CTN block, every `@in` declaration written for 5.0 works in 5.1 byte-for-byte. What changes is the PHP reference's call shape (`$C->mod()` is gone — use `$C->ctn()` everywhere) and the file layout that signals "this instance has no pages".

Read this end-to-end before upgrading. Short and concrete.

---

## What changed in one paragraph

The Core's two invocation methods (`$C->mod()` and `$C->ctn()`) collapse into one: **`$C->ctn($id, $args)`**, strict-schema-enforced, always returning a CTN string (`CTN: error` block on any failure — schema, throw, missing id, ambiguous stem). The 5.0 `WebStacker` splits into two role-named siblings: **`PlaceStacker`** (the place pipeline — PRG, dispatcher selection, acts dispatch, page wrap) and **`CtnStacker`** (single-container URLs — `.ctn` / `.raw` / `.htmx`-mod). CLI dispatch is promoted from a procedural file to a **`CliStacker`** class. And a new deployment shape — **headless** — lets an instance ship without `places/`, `skin/`, or acts: the framework detects the missing `places/` directory at boot and routes every request through CtnStacker. Useful for federation producers, BFFs, and CLI-consumed services that don't render HTML pages.

---

## Upgrade in three steps

### 1. Run the codemod on instance code

A codemod ships at `core/tools/migrate-mod-to-ctn.php`:

```sh
php core/tools/migrate-mod-to-ctn.php --dry-run
# review the changes the dry-run reports
php core/tools/migrate-mod-to-ctn.php
```

It walks `modules/`, `mods/`, `test/`, `lib/` (default scan) and rewrites `$C->mod(` → `$C->ctn(` and `$core->mod(` → `$core->ctn(`. Files under `core/` are skipped — instances should not edit the framework. Pass explicit paths to override the default scan.

### 2. Sync the new `core/` from the reference

```sh
rsync -a --delete --exclude=.DS_Store ~/sites/containerist-php/core/ ./core/
```

Or pull `containerist-php` from your source of truth and copy `core/` over. The reference is authoritative: never edit `core/` on a live instance.

### 3. Audit mod-to-mod calls

Mod-to-mod calls that previously relied on `$C->mod()`'s loose `intersect_key` filter (which silently dropped undeclared keys) now get full schema enforcement. A mod that called another mod with missing required args used to run silently with those args absent; under 5.1 it now produces a `CTN: error` block where the call site sat. That's the latent bug surfacing — fix at the call site (supply the required arg, or relax the callee's `@in`).

### 4. Audit always-CTN guard shapes

The codemod in step 1 rewrites the **call** (`$C->mod(` → `$C->ctn(`). It does NOT rewrite the **guard** around the return value. Pre-5.1 callers commonly branched on sentinel returns (`=== false`, `=== ''`, `try/catch`) that the always-CTN contract has retired. Those guards now take the wrong branch silently — error blocks pass the old "is a string and non-empty" check, hard-fail branches never fire, and dead `catch` clauses look like working error handling.

Lint the call sites with the audit tool — added in 5.2.2, packaged in `core/tools/`:

```sh
php core/tools/audit-always-ctn.php
```

It scans `modules/`, `mods/`, `test/`, `lib/` by default (skipping `core/`), and reports each guard site it finds with classification:

- **[A] Type A — hard-fail intent** (e.g. `if ($x === false)`, `if (!is_string($x) || $x === '')`). Original code wanted to error out when `ctn()` returned a sentinel. Rewrite the gate to `if (strpos($x, "CTN: error\n") === 0) { … }`.
- **[B] Type B — optional use intent** (e.g. `if (is_string($x) && $x !== '')`). Original code wanted to process only when `ctn()` returned content. Rewrite to `if (strpos($x, "CTN: error\n") !== 0) { … }`.
- **[TRY] try/catch wrapped around `$C->ctn()`**. The `catch` is dead code — `ctn()` no longer throws. Either drop the try/catch entirely or replace with the appropriate strpos gate.

The tool flags by pattern, not by auto-fixed certainty: each hit needs eyeballing. The `is_string($x_raw)` lobe will match `file_get_contents` results and HTTP response bodies too; the rewrite is appropriate **only** when `$x` actually came from `$C->ctn()`. The tool narrows by recent assignment (within 20 lines) but can't make the semantic call alone.

### 5. Smoke discriminator pin

Smokes that assert `CTN: error` somewhere in the response body will pass whether the leaked error is the right one or the wrong one (Core's "container 'foo' not found" vs the mod's intended "no foo, run /refresh"). Pair each `CTN: error` substring check with a discriminator pin on the user-facing message — see the 5.2.1 entry in `CHANGELOG.md` and `logs/2026-05-28-page-display-back-promotion.md` for the precedent.

### 6. Mod-load smoke (every mod through `./ctnr`)

A loud-failure catch-net: invoke every mod once via the CLI adapter and flag any `Call to undefined` / `Fatal error` / `Uncaught` / `Class not found` in the first line of output. Cheap to run, catches the once-a-day-cron mods that the URL smoke never hits:

```sh
for mod in $(find modules -name "*.php" -type f \
    | sed 's|.*/||;s|\.ctn\.php$||;s|\.html\.php$||;s|\.php$||' \
    | sort -u); do
  result=$(./ctnr "$mod" 2>&1 | head -1)
  if echo "$result" | grep -q "Call to undefined\|Fatal error\|Uncaught\|Class.*not found"; then
    printf "%-30s  ✗ %s\n" "$mod" "$result"
  else
    printf "%-30s  ✓\n" "$mod"
  fi
done
```

A mod that performs network or filesystem writes (e.g. build/refresh mods) **will** mutate state when invoked — invoke with care if such mods exist.

A quick sanity audit summary: codemod (step 1), `audit-always-ctn` lint (step 4), unit suite + HTTP smoke with discriminator pins (steps 3, 5), and mod-load smoke (step 6). Any new `CTN: error` blocks in the output then trace back to genuine schema-enforcement surfacings — fix at the call site (supply the required arg, or relax the callee's `@in`).

---

## What's the same

The intent of this list is reassurance, not exhaustiveness.

- **Wire format**: CTN, PLACE, IN, ACTS, FEDERATION specs are byte-identical to 5.0 at the grammar level (the IN-SPEC 1.1 and ACTS-SPEC 1.2 revisions are documentation/clarification only — grammar unchanged, conformance surface extended by §7.2 CLI exit-code rule). No mods need updating beyond the call shape.
- **The eight pillars** are unchanged. Pillar 8 ("Core is realm-free") gets one clarifying sentence about role-named Stackers — "one Stacker per (realm, role) pair" instead of "one Stacker per realm". The boundary itself doesn't move.
- **Skin format**: `.html` + `.css` per CTN type (optional `.js` triplet for interactive blocks) — unchanged.
- **The acts layer**: same seven core acts, same dispatch contract, same `error` act for `CTN: error` blocks (which now ALSO renders Core-emitted blocks alongside mod-emitted ones — same code path, just spelled out in ACTS-SPEC §5.3 as the *source-opaque dispatch* clarification).
- **Federation**: cross-origin transclusion contract unchanged.
- **CLI invocation**: `./ctnr <mod> key=val` still works; the procedural `dispatch.php` is replaced by a `CliStacker` class with the same argv parsing.

---

## What's new (capabilities)

- **Headless deployment shape**. Detect via `CONTAINERIST_HEADLESS` constant (auto-defined at boot when no `places/` directory exists, or it is empty). Headless instances route every URL through CtnStacker; non-CTN URLs become 404. No `places/`, no `skin/`, no acts needed. Production examples: a federation producer that exposes `/articles.ctn` and `/feed.ctn`; a backend-for-frontend that serves CTN to a separate UI; a CLI service that accepts HTTP requests as a transport for `./ctnr` invocations.
- **Always-CTN return contract**. `$C->ctn()` never returns `false`, never throws — always a CTN string. Schema failure → `CTN: error code 400`. Mod throw → `CTN: error code 500`. Missing id → `CTN: error code 404`. Ambiguous stem → `CTN: error code 500`. Callers `echo` the result without type-checking; the acts layer renders any error blocks inline. IN-SPEC 1.1 names this as the Core-side enforcement responsibility; ACTS-SPEC 1.2 §5.3 names the source-opaque dispatch rule.
- **Stacker decomposition**. PlaceStacker, CtnStacker, CliStacker — role-named, web realm implicit for the first two. Adding a new realm (MCP, SSE, future) follows the same shape: one Stacker class per (realm, role) pair.
- **CLI exit-code contract**. ACTS-SPEC 1.2 §7.2 normative: a CLI Stacker exits `1` when the returned CTN stream begins with `CTN: error`, exits `0` otherwise. The error block stays in the typed stdout stream — errors are typed output (Pillar 11). The exit code gives shell `&&` / `if` chains the binary success signal.
- **File-layout-as-config for deployment shape**. Same pattern that detects mods (scan `modules/`), containers (scan `containers/`), places (scan `places/`) now also detects deployment shape from the same scans. An LLM reading the repo can infer the shape from `ls` alone — no config flag to grep for.

---

## Behavior changes worth naming

- **Mod-to-mod calls now strict.** `$C->ctn('other', $args)` enforces the callee's `@in` and produces a `CTN: error` block on missing required. Previously `$C->mod()` would intersect-key-filter and silently run the callee with whatever made it through.
- **Ambiguous container stem now returns `CTN: error` instead of `E_USER_ERROR`.** The PHP fatal at lookup time is replaced by a structured 500 block. Registration-time `error_log()` warning unchanged.
- **CTN: error block emission is sanitized.** Newlines in `$id` or in exception messages are stripped before interpolation — they previously could have injected sibling frontmatter fields, breaking the block's wire shape.
- **CliStacker exits 1 on `CTN: error` (output unchanged on stdout).** A `CTN: error` block returned by the Core or a mod still lands on stdout — errors are typed output and stay in the typed stream — but the process exits 1 so `&&` / `if` shell chains see the failure. Pipelines (`./ctnr a \| ./ctnr b`) treat the body the same way regardless. Argv parse errors and "mod not found" stay on stderr with exit 1 (CLI-realm affordance, no Core call made).

---

## Spec impact summary

- **CTN-SPEC** — unchanged. `CTN: error` is already in the wire vocabulary.
- **IN-SPEC** — bumped 1.0 → 1.1. Grammar unchanged. §5.4 clarification: enforcement is a Core responsibility (single run at the Core's invocation boundary). The duplicated Stacker-side pre-filtering of pre-5.1 PHP-reference shape is non-conformance the impl now corrects.
- **PLACE-SPEC** — unchanged. The `.htmx`-routing-table clarification in `containerist.md` is documentation-tier.
- **ACTS-SPEC** — bumped 1.1 (draft) → 1.2 (draft). §5.3 source-opaque-dispatch clarification (`error` act handles Core-emitted and mod-emitted blocks byte-identically). §7.2 new normative exit-code contract for CLI Stackers. §10 conformance trail extended.
- **FEDERATION-SPEC** — unchanged.
- **`containerist.md`** — version bump to 5.1.0 / doc 3.1. Public Core API rewritten for the single-method `$C->ctn()` + error catalog. New "Deployment shapes" section. Vocabulary entry for *Stacker* updated for role-named classes. Pillar 8 wording extended with the *(realm, role) pair* clarification. URL-suffix table gains a "Stacker (5.1+)" column.

---

## Where to read more

- **`containerist.md`** — top-level briefing. Public Core API section now describes the single `$C->ctn()` method; new "Deployment shapes" section spells out full-vs-headless.
- **`ACTS-SPEC.md` §5.3, §7.2, §10** — source-opaque dispatch + CLI exit-code contract + revision trail.
- **`IN-SPEC.md` §5.4, §9** — Core-side enforcement clarification + revision trail.
- **`PLACE-in-PHP.md`** — PHP impl notes for places. Updated to reference PlaceStacker + CtnStacker by role.
- **`ACTS-in-PHP.md`** — PHP impl notes for acts. PlaceStacker runs the act-dispatch loop; CtnStacker bypasses it (single-container path emits CTN directly). CliStacker exit-code impl note included.
- **`logs/2026-05-26-5-1-back-promotion.md`** — full back-promotion arc, the second-instance-first reckoning, and the discipline lessons.

---

## Compatibility posture

5.1 is a **clean cut** at the Core API level: `$C->mod()` is gone, no transitional alias. The codemod is a one-shot mechanical migration. The wire format is unchanged, so place files, skin sets, and other Containerist instances at 5.0 wire-format conformance remain compatible at the federation boundary.

Per the framework versioning rule (ACTS-SPEC §10), this is treated as a PHP-reference minor release: the wire format (which the rule actually governs) is unchanged. A strict reading of the rule would have called this a 6.0 because of the Core API removal; we interpret the rule as wire-format-governed and treat this as 5.1. The same interpretation governed the 5.0 release (see `RELEASE-5.0.md` §"Compatibility posture") and is now applied consistently here.

---

## A word on the back-promotion path

The 5.1 implementation was authored on the konnexus-ai instance first — via the `superpowers:brainstorming` and `superpowers:test-driven-development` skill workflows in `docs/superpowers/specs/` and `docs/superpowers/plans/` — before any code touched the meta spec tier or the PHP reference. The meta spec tier was silent on 5.1 entirely (no spec text, no journal entry, no `*5.1 lands…*` paragraph) at the time the konnexus implementation was complete. The work was then back-promoted: lifted from konnexus into `~/sites/containerist-php/core/`, with the spec text drafted from the instance's design doc and shipped implementation rather than the other way around.

The 5.0 release notes (see `RELEASE-5.0.md` §"A word on instance-first prototyping") declared that the inversion was "deliberately and only once, for the spec's 0.x → 1.0 promotion." 5.1 was not a 0.x spec promotion — it was a 1.x additive spec change plus a Core API removal — so under that rule the 5.1 work should have started in meta, then reference, then instance. It didn't. The design doc even named the containerist-evolve workflow ("spec → reference → instance evolution") as the next step; that step was bypassed.

Two factors explain the drift without excusing it:

1. The 5.1 brainstorming and TDD happened under skill workflows that naturally produce artifacts in the instance's `docs/superpowers/` directory, where the brainstorm transcripts and implementation plans live. Once those artifacts existed and the TDD cycle ran red-green-refactor against the instance's test suite, the implementation surface that grew was the instance's `core/`. The "design somewhere, implement against the reference" shape isn't how brainstorming + TDD work in practice; they produce co-located design and impl on whatever surface the test suite exercises.
2. Once the konnexus implementation was passing tests, the path of least resistance was to ship it as the actual 5.1 release. The reference-leads discipline would have required a separate pass: rewriting the impl against the PHP reference, possibly with subtle divergences, and then re-syncing konnexus from the reference. That cost a known-good implementation for a discipline expectation.

The discipline lesson 5.1 earns, beyond what 5.0 already named: **when a framework evolution is going to be designed via brainstorming/TDD skills, the design step should explicitly include a back-promotion plan**, not just a "next: containerist-evolve workflow" pointer. The journal entry expands on what that plan should look like. The rule "instance-first is the right shape only when the spec is genuinely 0.x" remains correct; what 5.1 surfaced is that 1.x additive spec changes designed under skill workflows can drift into instance-first without anyone noticing, because the workflow output looks like a complete implementation.

5.2 and beyond MUST start in meta. If the design step naturally produces instance-side artifacts (because the brainstorming or TDD skill requires them), the design closure step MUST include a checkpoint that promotes the spec text into meta before any reference-side implementation begins.

---

*Containerist 5.1. Same wire format, fewer methods, one new deployment shape, honest about the path it took.*
