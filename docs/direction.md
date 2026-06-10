# Containerist — Direction

*Forward-looking companion to `docs/containerist.md`. Where the operator briefing describes "what is" — the frozen pillars — this document records "what this is becoming": intents we've named, costs we've gauged, risks we're watching. The operator briefing is authority. This is ambition. Concrete plans live in `plan.md` (currently: Web + CLI Stacker implementation for konnexus.net).*

*Every entry here is provisional until promoted to `docs/containerist.md`. A change in the operator briefing is a change in authority; a change here is revising intent.*

*As of the 2026-04-18 briefing revision, the first batch of intents is **Realized** — Core/Stackers separation, in-process web Stacker, CLI Stacker, skin/CTN-renderer as infrastructure-mods, 5th review criterion (G4) — and their substance has been folded into the operator briefing. Kept here as history so the original cost/risk/decision reasoning stays readable. Several architectural refinements that emerged during implementation (typed mods, control-flow CTN blocks, suffix dispatch, `stack-ctn`/`skinner-ctn`/`page-wrap` decomposition, Parsedown replacement) went directly to the briefing without a direction-file intent; see `logs/2026-04-18.md` for the realization trail.*

---

## Reading order

- **Understanding the system?** Start with `docs/containerist.md` (or `briefing-detailed.md` for the long form).
- **Proposing changes?** Check this document first — an intent may already cover it.
- **Doing the work?** Follow `plan.md` when it exists. Until then, this document is the operational ceiling.

## Status tiers

- **Necessary** — structural consequence of existing pillars. No real choice; don't second-guess.
- **Settled** — deliberate decision, made. Could change by re-decision.
- **Realized** — implemented in working code. The intent is now load-bearing, not aspirational. Kept in this file as history until the next briefing revision folds it into `docs/containerist.md`.
- **Proposed** — written down, consensus pending. May be adopted, revised, or rejected.
- **Open** — active question. Not yet answered.

## Vocabulary

Tightened from our working conversations. Intents below use this vocabulary consistently.

- **Containerist** — the whole system. Core + Stackers + modules + containers. The brand, the repo, the mental model.
- **Core** — the CTN producer: mod registry, execution engine, CTN parser. ~80 lines. Named in briefing pillar 8 (*"Containerist core is realm-free"*).
- **Stackers** — clients that fetch, request, and/or render containers. Part of Containerist, whether shipped in-repo (web, CLI) or added later (CUI, MCP, webhook, mobile).
- **Modules** — directories grouping mods by feature or domain (e.g. `modules/posts/`). The word *mod* (modifier) stays for individual files; *module* only names the grouping unit.
- **Containers** — static containers in `containers/**/*.txt`. The zero-transformation case of a container-producing unit.

---

## Intents

### Core / Stackers separation — *Realized*

**Statement.** Promote pillar 8 from discipline to structural property. The **Core** narrows to "CTN producer + thin auth + in-process/CLI/HTTP API." All realm-specific logic lives in **Stackers** — clients that fetch, request, and/or render containers. Together, Core + Stackers = Containerist.

**Rationale.** Today "realm-free Core" is maintained by discipline. After the separation, it's enforced by construction: the Core cannot be realm-locked because nothing in it talks to a realm. Also: the "edge mod" concept (briefing § 10.6) becomes first-class under a proper name.

**Cost.** Medium. Mostly refactoring existing code into named directories plus adopting new vocabulary consistently.

**Risks.**
- Two invocation paths (in-process for the web Stacker, HTTP for out-of-process Stackers) drifting apart. *Mitigation:* HTTP API is a pure wrapper over `$C->mod()`, never its own logic.
- "Stack" concept migrating from the Core to Stackers — a reframing of pillar 2 that needs clear communication in the next briefing revision.

---

### One in-process web Stacker, side-by-side in the repo — *Realized*

**Statement.** Ship *one* privileged web Stacker alongside the Core in the same repo. It uses `$C->mod()` in-process — no HTTP overhead, no serialization. Composes full pages server-side and serves HTML.

**Rationale.** Preserves today's performance and reliability for konnexus.net and similar. The architectural separation lives at the protocol level; the performance level pays nothing. The 2005 G4 PowerBook test still passes.

**Cost.** Low. Mostly a relabel of current `index.php` as "the web Stacker." File organization changes; invocation pattern does not.

**Risks.**
- The privileged Stacker reaches into Containerist internals. *Mitigation:* web Stacker uses *only* the public `$C->mod()` API, never internals. Privilege is transport, not access.
- The "one canonical Stacker" becomes a dumping ground for logic that should be mods. *Mitigation:* the Stacker stays a thin shim — route, invoke, render, return.

---

### HTTP API as pure wrapper over `$C->mod()` — *Settled, deferred*

*(Not built in `konnexus.net`. The in-process web Stacker is sufficient for konnexus. This intent activates when an out-of-process Stacker is added — MCP Stacker, for example.)*

**Statement.** When external Stackers land, the HTTP endpoint is a pure wrapper: deserialize args from request, call `$C->mod()`, serialize CTN response. No separate invocation logic.

**Rationale.** Prevents drift between in-process and external call paths. One execution path, two entry doors — Unix-socket-vs-TCP pattern.

**Cost.** Low at first (a thin PHP file). Medium as error semantics, auth, streaming, batching mature.

**Risks.** The HTTP layer grows its own logic (request caching, arg transformation, response munging). *Mitigation:* every addition reviewed against "is this a pure wrapper?" — if the answer is no, push it into a mod or a Stacker.

---

### CLI Stacker — *Realized*

**Statement.** Formalize `./ctnr` and `cli.php` as a first-class Stacker. The CLI Stacker is the degenerate case: reads args from shell, invokes a mod, writes CTN to stdout.

**Rationale.** Briefing already names CLI as one of three realms (pillar 6), and the "cheapest realm for testing" principle (criterion 3) depends on it. Without a CLI Stacker, the testability story collapses. Not a decision; a structural requirement.

**Cost.** Very low. Mostly vocabulary — recognizing that what already exists *is* the CLI Stacker.

**Risks.** The CLI Stacker accrues mod-specific heuristics (as the current `ctnr` does). *Mitigation:* declared `@in` in each mod replaces heuristics with generic key=value resolution (see briefing § 10.4).

---

### Skin and CTN-renderer live in the web Stacker — *Realized, extended*

*Extended during Phase 2.5 to "infrastructure-as-mods": `stack-ctn`, `skinner-ctn`, `skinner-block`, `page-wrap` are all mods. WebStacker just wires them together. Skin files (`skin/*.html`/`*.css`) are consumed by `skinner-block` and `page-wrap`. As of 2026-04-19 the renderer is full Mustache (sections, inverted sections, raw/escaped output) — the Phase 1 "minimal substitution" stopgap is closed. Same day: pillar 3's `.css` half is now wired end-to-end — `page-wrap` emits `<link rel="stylesheet">` for each CTN type rendered on the page; `.htmx`/`.html` fragments prepend their own `<link>`s. Until this fix the `.css` files were documentation-only — only `stack.html`'s inline `<style>` block was live. The `htmx:` stack-line prefix (post-loaded containers — taps, series-view, backlinks, related, terms-view) lives on prod in `stacks/n--*.txt` (not the stale-mirror's `nx--*.txt`, which earlier docs cited in error). (Detailed rationale is kept in the project's internal development log.)*

**Statement.** The `skin/` directory (HTML + CSS templates) and the HTML-producing CTN renderer (Mustache-based) live inside the web Stacker, not the Core. The CTN *parser* stays in the Core (pillar 1); the *renderer* is per-Stacker.

**Rationale.** Once the Core narrows to "CTN producer + thin auth," HTML rendering has no coherent home in it. Rendering is always realm-specific — HTML for web, JSON for MCP, stdout/ANSI for CLI. Each Stacker owns the renderer appropriate to its output.

**Cost.** Very low. Move existing `skin/` and renderer code into the web Stacker's directory. File paths change; logic does not.

**Risks.**
- Pillar 3's current wording mentions `skin/{type}.html` as if it were core architecture. *Mitigation:* the next briefing revision should separate the universal rule (flat blocks, one type = one render path) from the web-specific implementation (`skin/{type}.html` + `.css`).
- Other Stackers silently re-invent rendering patterns. *Mitigation:* each Stacker's renderer is documented alongside the Stacker itself.

---

### Fifth review criterion: the G4 constraint — *Realized (promoted to briefing)*

**Statement.** Add a fifth question to the review criteria: *"Does this degrade the experience on a constrained device — old PowerBook G4, slow network, limited JS?"*

**Rationale.** Not nostalgia. A testable discipline that reinforces existing commitments: server-render = one canonical page; no fat client = HTML is the product; minimal CSS = skin layer stays honest. Also: an anti-drift vaccine — LLMs reach reflexively for React/Alpine/fancy-client; the G4 question catches 90% of that drift in one sentence.

**Cost.** Very low. Adds one line to the briefing's criteria section.

**Risks.** The G4 criterion becomes a blocker for legitimate interactivity (e.g., CUI-summoned widgets, HTMX affordances). *Mitigation:* phrase as *degradation*, not *prohibition*. A feature may rely on JS as long as the core reading experience still works without it.

---

### MCP Stacker — *Proposed*

**Statement.** Ship an MCP Stacker that exposes Containerist to LLM clients via Model Context Protocol. Tool calls map to `$C->mod()`; CTN responses are wrapped in MCP JSON format.

**Rationale.** Turns Containerist into an addressable knowledge-and-function layer for any MCP-speaking LLM (Claude Desktop, Claude Code, etc.). A cheap way to prototype the CUI design variables (Intentionstreue, Memory, Vergessen) using an existing LLM frontend — before building a dedicated CUI Stacker.

**Cost.** Low. An MCP Stacker is thin — protocol adapter + tool/resource mapping. Can ship in-process (stdio) or external (HTTP), depending on deployment.

**Risks.**
- Mod signatures become implicit LLM-tool contracts. *Mitigation:* the declared `@in` (briefing § 10.4) is already the contract shape; MCP tool schemas derive from it.
- LLM clients start depending on specific mod outputs, making mods harder to evolve. *Mitigation:* treat MCP-exposed mods as public API; version or subset them if needed.

---

### Canonical patterns library — *Proposed, scoped*

**Statement.** Write five worked examples that demonstrate "mod + edge + Stacker" patterns for common maker use cases:

1. DB-backed mod (SQLite first).
2. Stripe webhook Stacker.
3. Auth edge pattern (session → core mod).
4. HTMX-interactive CTN type.
5. Deployment story.

**Rationale.** The briefing gives substrate. These examples turn substrate into platform. Until a maker sees *how* a DB-backed mod looks under the pillars, they'll try to make it Laravel-shaped.

**Cost.** Medium each, high together. Each example is a real worked implementation plus a short companion note explaining the design choices.

**Risks.**
- The examples ossify into "the right way." *Mitigation:* present them as exemplars, not templates.
- The examples introduce de-facto pillars by pattern-weight. *Mitigation:* every pattern must be re-derivable from the existing pillars; if a pattern requires a new pillar, flag it as an *open* intent, not a silent addition.

---

### CUI Stacker as exploration vehicle — *Proposed, speculative*

**Statement.** Build a CUI Stacker that uses LLM intent resolution to compose stacks at runtime. Use it to research the design variables from `inspiration/K260416A-formbarkeit-des-user-interface.txt`: Intentionstreue, Memory, Vergessen, user-controlled Ephemeralität.

**Rationale.** Containerist's primitives (CTN-type + skin pair, trace-for-transparency, mod-statelessness as default forgetting) align unusually well with the CUI-with-materialization design space. Could be one of the best workbenches for this research, in the prototyping-and-exploration sense — not a competitor to Claude.ai on feel.

**Cost.** High. Needs new primitives: conversation turn as CTN block, intent-router mod, pin primitive (writes a mod into a persistent stack), memory primitive with explicit scope.

**Risks.**
- Primitives sneak CUI-specific assumptions into the Core. *Mitigation:* shape every primitive as a mod or Stacker, never as a change to the Core.
- Pillar erosion via "we need this for the CUI thing." *Mitigation:* the CUI Stacker is a playground; pillar changes must still be debated against the full briefing, not against the CUI's local needs.
- Server-render limits CUI fluidity. *Mitigation:* accept as a research constraint — the goal is to explore design variables, not to compete on feel.

---

### URL `.trace` suffix dispatch — *Realized*

*Landed 2026-04-19 as Phase 7.7. Both activation paths work: `.trace` URL suffix or `?trace=1` query flag. Guarded by `TRACE_ENABLED` in `config.php`, default `false` — production-safe.*

**Statement.** Add a `.trace` URL-suffix dispatch (or `?trace=1` query flag) to the web Stacker that, instead of rendering, returns a structured debugging trace: wildcard candidates tried in priority order, resolved `stack_id`, `@args` bindings applied, each container invoked with its final args, size and first bytes of each mod's output, control-flow block detections, total timing.

**Rationale.** Closes briefing § 10.7. LLM-maintainability principle 2 asks "what runs when I hit URL X?" to be answerable from the entry point; the three-document architecture answers this *conceptually* but not *runtime-observably*. `.stack` and `.stackctn` give partial inspection already; `.trace` completes the picture. Also useful for humans diagnosing a stuck route.

**Cost.** Low. Implementation is ~160 lines in WebStacker (plain-text output; pure PHP; no JSON framework). Single new helper `emit_trace()` + ~10 lines of dispatch wiring at the top of `handle()`.

**Risks.**
- Trace output becomes a leak surface for internals a production site shouldn't expose. *Mitigation (realized):* `TRACE_ENABLED` constant in config.php, default `false`. When off, the trace dispatch is skipped entirely — `.trace` URLs fall through to normal render, `?trace=1` is ignored. Prod-safe out of the box; flip to `true` only when actively debugging.
- Instrumentation creeps into `stack-ctn` and pollutes the hot path. *Mitigation (realized):* `emit_trace` is a standalone method in WebStacker that re-walks the wildcard search and re-runs stack-ctn's argument resolution inline with logging. The production rendering path (`stack-ctn`, `skinner-ctn`, `page-wrap`) is untouched.

---

### Tailwind CSS at the TS skin layer — *Realized (2026-04-23)*

*TS-only. PHP and Go skins keep their existing `.html` + `.css` per-type convention. CTN and STACK formats unchanged; mods and stack files remain byte-identical across impls. The divergence is at the presentation layer only.*

*Implementation notes:* Tailwind **v3** (not v4) because v4's Oxide engine requires Node 20+ and this project runs on Node 18. Revisit on runtime upgrade. Integrated via the standalone `tailwindcss` CLI rather than Next's PostCSS pipeline, because the catch-all route handler returns raw `Response`s and bypasses `app/layout.tsx` (where Next-managed CSS imports would live). Compiled CSS lands at `public/tailwind.css`, served at `/tailwind.css` by Next's public-folder precedence. The `inlineStylesFromTypes` machinery + `css_types` set + `{{{styles}}}` template slot were deleted — per-type CSS escape hatch removed in favor of `styles/tailwind.css` as the single global input. See `SKIN-in-TS.md` for authoring details.

**Statement.** The `containerist-ts` impl uses Tailwind CSS at the skin layer. Skin HTML carries utility classes directly; a global compiled Tailwind stylesheet replaces the per-type `.css` files. Details in `SKIN-in-TS.md`.

**Rationale.** LLM iteration speed on frequently-touched prototypes. Utility classes at the use site:
- Satisfy manifesto P1 (minimize token load per task): styling lives next to markup, no cross-file hop to a sibling `.css`.
- Satisfy manifesto P5 (colocation): the maintainer changing padding sees the padding class in the same line they'd have edited for markup.
- Satisfy manifesto P4 (enumerable paths): no runtime, no hydration. Tailwind is a build step producing static CSS; the G4 constraint holds.

React, Vue, and other component frameworks remain banned for reasons documented in `/containerist-ts/FRONTEND-POLICY.md` — a TS-specific allowed/banned list that makes Principle 7 ("freeze what the system must not become") concrete for the JavaScript-ecosystem drift vector.

**Cost.** Small.
- Code: ~30 LOC added (globals.css, postcss.config.mjs, layout.tsx import), ~50 LOC removed once all skins migrate off per-type `.css` (delete `inlineStylesFromTypes` + the per-type CSS walks). Half a day of focused migration.
- Dependency: `tailwindcss@^4` + `@tailwindcss/postcss`. Both devDeps, no runtime weight.
- Documentation: `FRONTEND-POLICY.md`, `SKIN-in-TS.md`, `ACTS-in-TS.md` skin-section amendment, journal entry — written 2026-04-23 ahead of implementation.

**Risks.**
- *Quiet drift from Tailwind-only to "and also shadcn/ui, and also Radix, …"* — the pattern Principle 7 warns against. *Mitigation:* `FRONTEND-POLICY.md` lists banned libraries by name with the manifesto principle each violates. Each new allowed tool requires a proposal through this document.
- *Cross-impl skin divergence.* Skin HTML between PHP and TS is no longer byte-identical. *Mitigation:* named explicitly. Mods and stacks stay byte-identical; that's the portability contract. Skin is presentation, not content.
- *Build step dependency.* Next.js is already the build layer; Tailwind plugs into its existing pipeline. No new build tool, no new runtime.

**Scope boundary (not in this intent).**
- No React. No Vue. No Svelte. No component libraries. No client-side state. See `/containerist-ts/FRONTEND-POLICY.md` for the full banned list.
- PHP skins: unchanged. Go skins: unchanged.
- CTN-SPEC, IN-SPEC, STACK-SPEC, ACTS-SPEC: unchanged (ACTS-SPEC §5.1 already defers CSS delivery to per-impl sidecars).

---

### Explicit `$C` in mod signatures — *Proposed, lower priority*

**Statement.** Make the `$C` dependency inside mods legible at point-of-read rather than implicit-via-`extract()`. Options considered:
- **(a)** Require each mod to start with `global $C;` or `$C = Containerist::instance();` — ugly but honest.
- **(b)** Add `$C` as an implicit `@in` input the Stacker always provides — mod body declares `@in: note_id (required)` *and* still sees `$C` from args, same shape.
- **(c)** Leave as-is, document the implicitness as a deliberate cost — `$C` is the one framework-level dependency the mod sees without declaring, and the briefing names it as such.

**Rationale.** LLM-maintainability manifesto principle 3: a function's inputs should be visible in its signature. Currently a mod does `$C->mod('article', [...])` without any declaration that `$C` is in scope — the stateless reader has to know Containerist's convention. `@in` does this well for other inputs; `$C` is the glaring exception.

**Cost.** Low per mod, but touches every ported mod. Also needs a lint update.

**Risks.**
- (a) every mod gets noisier by one line. *Mitigation:* worth the legibility.
- (b) `$C` being in `@in` is weirdly recursive (the mod's own dependency is declared as an input it receives). *Mitigation:* acceptable pattern — `$C` is effectively the framework-supplied runtime.
- (c) leaves the manifesto gap open. *Mitigation:* document the intent as deliberate prototyping cost; revisit if confusion compounds.

**Lower priority** because the `@in` convention already eliminates 90% of implicit-input pain. `$C` is the remaining 10%, and it's the *one* framework-level dependency, not a floating magic global.

---

### Per-line stack args + Mustache reference grammar — *Proposed*

*One coherent proposal with two coupled pieces: per-line args syntax in stack bodies, plus a grammar migration from `$N`/`$name` to `{{N}}`/`{{name}}` in `@args` bindings and per-line args. They ship together because they share one substitution vocabulary — deferring the grammar migration would force a second migration later. `@in` mod headers are intentionally NOT extended: per the "mods don't know where they're placed" principle, mod headers declare only names, never sources.*

**Statement.**

1. **Per-line args on container references.** A stack body line may carry a query-string-shaped argument list after the container name: `digest-nav?placement=top`, `digest?date={{date}}&refresh=true`. Per-line args layer on top of the stack's resolved `@args` dict before the container is invoked. Each invocation is independent — the same mod can be called twice in one stack with different args, collapsing the "duplicate-mod-just-to-vary-a-flag" pattern (`digest-nav` + `digest-nav-footer`).

2. **`{{N}}` / `{{name}}` reference grammar.** Replace `$N` (positional URL ref) and `$name` (named ref) with `{{N}}` and `{{name}}` across `@args` bindings and per-line args. Rationale: language-neutral (the `$` sigil reads PHP-ish / shell-ish to a reader coming in cold, which matters now that a second implementation — `containerist-go` — consumes the same stack files); aligned with Mustache-style syntax already used in skin templates; self-explanatory to LLMs and authors without framework context.

**Rationale.**

- **Real pain, real simplification.** `digest-nav` + `digest-nav-footer` exist only because stacks can't invoke the same mod twice with different args. The LLM-first review of `reader.konnexus.net` and `sites/reader.konnexus.net.md` both flagged this; per-line args closes it.
- **Two implementations make grammar portability load-bearing.** `containerist-go` exists at `containerist-go/` and is under active development. Any grammar that reads PHP-ish in stack files (like `$1`) will either be replicated in Go (hardcoding PHP convention into a second language) or diverge (the worse outcome). `{{…}}` reads neutrally in both. The migration moment is now, not later — migrating one implementation is a sed pass; migrating two is a coordination problem.
- **One substitution vocabulary across the framework.** Skin templates already use Mustache `{{title}}`. Stack refs using `{{1}}`, `{{date}}` now read the same way. One mental model, not two.
- **`@in` stays pure.** A mod receives named arguments and returns CTN. Where the names came from — URL part, query string, CLI arg, MCP tool call, test fixture — is not the mod's concern. This corollary of briefing pillar 8 (Core is realm-free) extends the realm-free property to every mod.

**Cost.** Low-medium. ~150 LOC framework-side + mechanical stack-file migration. Breaking for grammar syntax; additive for per-line args.

- **Spec changes.** `STACK-SPEC.md` bumps 1.0 → 2.0 (grammar migration is a breaking change). `IN-SPEC.md` stays at 1.0 with the pure grammar (no chain, no sources).
- **PHP implementation (`konnexus.net`, `reader.konnexus.net`).** `modules/core/stack-ctn.ctn.php` — parse `{{…}}` refs (replacing `$…`); add per-line args parsing (split-on-first-`?`, `parse_str`, substitute refs against resolved dict, merge). ~40 lines touched/added. `stackers/web/WebStacker.php` — no change. `lib/ctn_in_parser.php` — no change (`@in` is unaffected).
- **Go implementation (`containerist-go`).** Picks up the new grammar from day one. The earlier in its development the change lands, the less migration it needs.
- **Stack-file migration.** One `sed` pass over `stacks/*.txt` in `konnexus.net` and `reader.konnexus.net` (`$N` → `{{N}}`, `$name` → `{{name}}`). ~40 files total; mechanical with manual verify for edge cases.
- **Briefing.** Amend pillar 2 wording from "flat list of container *names*" to "flat list of container *invocations*" (name + optional args); add a one-line corollary to pillar 8 ("A mod's input names are its entire public surface; where those names came from is not the mod's concern."). ~10 lines.

**Risks.**

- **Breaking grammar change.** Any stack files still using `$N` after migration will silently break. *Mitigation:* migration is a single scripted pass on a known file set; verification is direct (grep for `\$[0-9]` in `stacks/` → should return nothing post-migration).
- **`{{…}}` collides with Mustache's render-time substitution.** Visually identical; semantically different (stack-compose-time vs template-render-time). *Mitigation:* the two never appear in the same file — stack files don't use skin templates, skin templates don't use stack refs. `STACK-SPEC.md` and `docs/containerist.md` name the distinction explicitly.
- **Per-line args as a slippery slope.** Authors might pack composition logic into stack lines. *Mitigation:* the "parts stay simple" pillar catches this in review; the grammar deliberately excludes conditionals, loops, or mod-name computation.

**Prerequisites.** None. Stand-alone change, landing alongside the spec split (below).

---

### Alias-default `.html` URL suffix — *Proposed*

**Statement.** `.html` becomes an *alias-default* URL suffix: a URL with `.html` is semantically identical to the same URL without it. `/articles/2026/foo.html` serves the same as `/articles/2026/foo`. No extra stack file required. Today `.html` is a strict alias for `.htmx` (HTML fragment, no page shell); this proposal flips it to mean "canonical full page, same as suffix-less URL."

**Rationale.** External actors routinely append `.html` to URLs (browser autocomplete, cached link aggregators, old bookmarks, search engines). Under today's semantics those hits serve HTML fragments without a page shell — visibly broken. Under the proposed semantics they serve the canonical page. Matches universal web convention, requires zero per-site configuration.

The previously-documented workflow "export-via-URL fragments with `.html` extension" loses its shortcut; users of that workflow switch to explicit `.htmx`. Neither `konnexus.net` nor `reader.konnexus.net` currently rely on this.

**Cost.** Tiny. 3–5 lines in `stackers/web/WebStacker.php` (change `.html` branch from htmx-alias to full-page-alias). Two lines in `config.php` if we expose the alias-default suffix list as config (default: `['html']`). One briefing note.

**Risks.**

- **Breaking for `.html` fragment-export workflows.** *Mitigation:* release note; fix is one-character (`s/\.html/\.htmx/` in the affected URL pattern). No known affected sites.

**Prerequisites.** None.

---

### Spec split — portable specs + per-language implementation guides — *Proposed, organizational*

**Statement.** Every language-neutral normative spec (`CTN-SPEC.md`, `STACK-SPEC.md`, `IN-SPEC.md`) gets a per-language implementation guide sidecar: `CTN-in-PHP.md`, `STACK-in-PHP.md`, `IN-in-PHP.md`, plus parallel `CTN-in-Go.md`, `STACK-in-Go.md`, `IN-in-Go.md`. The portable specs contain *only* language-neutral normative content. The implementation guides are informative — each opens with "This document is informative. If it conflicts with [SPEC], the spec wins." — and hold the language-specific library choices, idioms, and historical-implementation notes.

**Rationale.**

- **Portable specs must not leak language-specific concerns.** Today `CTN-SPEC.md` §8.3 lists Symfony YAML / Spyc / yaml.v3 / ruamel, and §10 tracks PHP parser defects. Valid content, wrong layer — those concerns belong one layer down.
- **Two implementations exist, so drift is a present concern.** `konnexus.net` (PHP, production) and `containerist-go` (Go, under development) must answer to the same portable spec. Language-bleed from one implementation into the authority doc would pull the other along.
- **Future ports get a template.** A Rust or Python port adds `-in-X.md` sidecars; portable specs stay stable; sidecars evolve with their tooling. Clean scaling pattern.

**Cost.** Low. One-time: extract PHP-specific content from `CTN-SPEC.md` into `CTN-in-PHP.md`; extract Go-specific content into `CTN-in-Go.md`; stand up short stubs for `STACK-in-{PHP,Go}.md` and `IN-in-{PHP,Go}.md` (those portable specs have little language-specific content today).

**Risks.**

- **Drift between portable spec and sidecar.** *Mitigation:* every sidecar declares itself informative and yields to the spec on conflict.
- **Sidecar rot.** Language-specific claims falling out of date. *Mitigation:* sidecars date any implementation-state claim ("PHP 7.4, Symfony YAML 5.x, as of 2026-04-21").

**Prerequisites.** None. Ships alongside the grammar/per-line-args proposal above.

---

## Open questions

- **Where do stacks live after the Stacker split?** In the web Stacker? In Containerist as shared data? Both? Not every Stacker needs stacks (a webhook receiver doesn't), so probably: each Stacker owns its own stacks, and Containerist is oblivious.
- **Auth model for external Stackers.** Token per client? Scope per mod? Per-caller rate limits? Unspecified today.
- **Streaming / real-time.** The first real pressure point on request/response shape. Will a WebSocket-based Stacker need a protocol extension, or does CTN-as-stream work as-is?
- **Error semantics in the HTTP API.** CTN block of type `error` (briefing § 10.2) handles the data shape. HTTP status codes and headers are TBD.
- **Memory primitive for the CUI Stacker.** Minimum-viable shape? A mod that reads `memory/*.md` and returns relevant slices? A stateful Stacker-side store? Undecided.
- *(Per-line mod args in stack files — superseded 2026-04-21 by the "Named-arg provenance" proposal above, which covers this as one of three pieces. Retained as a pointer in case anyone follows a link to this bullet.)*

---

## Risks being watched

Cross-cutting risks, not tied to a single intent.

- **Protocol drift.** Web Stacker and HTTP API invoking mods in slightly different ways. *Mitigation:* HTTP API as pure wrapper is enforceable by review.
- **Pillar 2 reframing.** Stacks migrate from the Core to Stackers. Readers who learned the old framing need a clean path to the new.
- **LLM drift toward framework patterns.** LLMs will try to add middleware, DI, autoloading, MVC as features "grow." The four review criteria plus the G4 criterion are the main defense. Watch for drift in PRs and in mod-authoring patterns.
- **CUI Stacker contaminating the Core.** Exploratory work has a way of shaping substrate. The CUI Stacker must remain a *client*.
- **"Necessary" being misread as "settled."** Necessary items are structural consequences; they cannot be re-decided without re-deciding their parent pillar. Settled items are deliberate choices; they can be revisited.

---

## Decisions not to do

Records of things we considered in working sessions and chose not to build. Kept so future readers (or future instances of us) don't re-litigate. If circumstances change, an entry moves back to *Open questions* or becomes a full proposal.

### Variable-depth wildcards (`**`) in stack patterns — *Decided against 2026-04-21*

A pattern like `archive--**.txt` would match `/archive/2026`, `/archive/2026/04`, `/archive/2026/04/15`, etc. — one stack file absorbing every depth.

**Why not.** Matcher complexity grows meaningfully: the 2^n wildcard enumeration stops applying; priority rules need a new tier (`**` vs `*` vs literal); `{{N}}` semantics inside a variable-depth match become shaky (which N is which?); `ls stacks/` stops revealing a site's URL shape at a glance. And no konnexus- or reader-shaped site today has a URL hierarchy deep enough to need it — known-depth patterns (`archive--*.txt`, `archive--*--*.txt`, `archive--*--*--*.txt`) cover every real case while keeping the directory listing honest.

If a future site has a genuinely variable-depth hierarchy (deep documentation tree, hierarchical tag browser), this design reopens alongside `{{last}}` / `{{penultimate}}` named refs, which would earn their keep only in that context.

### Mod-level URL-position bindings in `@in` — *Decided against 2026-04-21*

A syntax like `// @in: id = {{1}} (required)` would let a mod declare its own URL-position source, recovering the pre-4.2 `$request->second` capability.

**Why not ever.** A mod is realm-agnostic: it receives named args and returns CTN, and it has no knowledge of where those names came from (URL, stack, query string, CLI, MCP, test harness). Letting a mod declare `{{1}}` couples it to HTTP URL structure — exactly the impurity that 4.2 banned. Recovering that capability under a polite syntax would revert the gain. URL-position-to-name binding lives in the stack (`@args`), the query string, or the explicit caller args — never in the mod.

### Source chains in `@in` — *Decided against 2026-04-21*

A syntax like `// @in: placement = {{placement}}, {{2}} (default: "top")` would let a mod declare an ordered list of sources with a literal fallback.

**Why not.** The proposal existed only to serve the URL-position extension above; without that, there's nothing to chain. The 95% case is "one name + default," which the pre-1.0 `@in` grammar already handles cleanly. If a real case ever needs per-source provenance *inside* a mod, the design reopens.

### Content-format URL suffixes (`.rss`, `.json`, `.atom`, etc.) — *Decided against 2026-04-21*

A URL like `/articles/2026.rss` would match a stack `articles--*.rss.txt` to serve an RSS feed for year 2026. Would require a three-class suffix grammar (dispatch / alias-default / content-format) with a strict-404-on-no-match rule.

**Why not.** Sub-paths express the same intent without framework weight: `/articles/2026/feed` served by `articles--*--feed.txt`. Sites give up a small URL-aesthetics win (`.rss` in the URL) in exchange for the framework staying at a single-class suffix model. Aligns with pillar 1 (*complexity belongs in composition, not in parts*) — slightly longer URL is composition; grammar extension to the matcher is parts.

If a future site has RSS-per-anything as a primary interaction model, this design reopens.

---

## Not yet in scope

Briefing § 9 lists what Containerist must not become — Composer, ORM, middleware, DI containers, component trees, nested CTN, template inheritance, plugins, framework-level caching. *Direction* respects all of them. Any intent that flirts with these must name the exception and prove (a) which pillar it affects, (b) what the replacement architecture is, (c) why the replacement is better for the four simplicities.

---

*This document is updated deliberately, not accidentally. Add intents when you name them. Promote intents to the briefing only by explicit decision. Downgrade intents when they are no longer intended. The briefing is the design authority. This document is its working edge.*
