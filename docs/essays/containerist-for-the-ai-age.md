# Containerist for the AI Age

*A declaration of intent, potential, and boundaries for an AI-realm Stacker.*

**Version:** 0.2 — May 2026
**Author:** Konstantin Weiss, with an LLM as drafting partner
**Status:** Working document. Not a spec. Not a manifesto. A basis for design conversations across sessions.

---

## How to use this document

This document exists to be handed to a future LLM (or future me) at the start of a session, so the conversation about building an AI-realm Stacker for Containerist does not restart from zero every time.

Read it once, end to end, before proposing anything. Treat the **What this must not become** section as binding unless explicitly lifted in conversation. Treat the **What is unsolved** section as the active research agenda — these are not problems to paper over.

When the architecture or the open questions shift, update this document. A stale briefing is worse than none.

---

## Referenced documents

The following documents are the context this declaration draws on. A future session should have all of them available before serious design work begins.

**Substrate (the existing Containerist corpus):**

1. **`containerist.md`** — operator briefing. The pillars, the vocabulary, the file layout, the act dispatch model. The authoritative summary of what Containerist is.
2. **`CTN-SPEC.md`** — wire format for CTN blocks. Normative.
3. **`STACK-SPEC.md`** — stack file grammar (`@args`, wildcards, per-line args, federation refs). Normative.
4. **`IN-SPEC.md`** — `@in` mod-input contract grammar. Normative.
5. **`ACTS-SPEC.md`** — Stacker-layer act dispatch, the seven core acts, the arg-lifecycle realization. Normative.

**Prior thinking (the path to this document):**

6. **The Page Paradigm** (Mark Hurst, 1999; K131208B annotations). The page-by-page evaluation model that CUI partially supersedes.
7. **Why architecting information with containers?** (K150215A, 2015). The original containerist argument: copies vs. hyperlinks vs. containers.
8. **Formbarkeit des User Interface** (Weiss, April 2026). Source of *intentionstreue*, trust-without-consistency, memory-as-design-variable.
9. **Manifesto for LLM-Maintained Code.** Engineering discipline for systems built and maintained by LLMs from scratch each session.

If any substrate document is missing from a session, request it before proposing structural changes. The prior-thinking documents are helpful context but the substrate is binding.

---

## What this document is, in one sentence

This document declares the intent, the potential, and the boundaries of a new **Stacker** for Containerist — an AI-realm Stacker — that composes CTN blocks into interactive surfaces under the direction of a language model interpreting user intent, rather than under the direction of a pre-authored stack file.

Everything below elaborates that sentence.

---

## What stays the same

The Containerist substrate is unchanged by this work. The AI-realm Stacker is a new realm adapter that joins the family (Web Stacker, CLI Stacker, MCP Stacker, …) without altering anything below it.

Specifically:

- **CTN block format** is unchanged. The wire format defined in `CTN-SPEC.md` is the substrate for all realms.
- **Containers** (mods and static files) are unchanged. A mod written for the Web Stacker can be invoked by the AI Stacker if they share an implementation language.
- **`@in` contract** is unchanged. Mods declare their inputs once; every Stacker honors the same contract.
- **Skin pairs** are unchanged. Block types render via `skin/<type>.{html,css}` regardless of which Stacker assembled the block stream.
- **Acts** are unchanged in principle. The AI Stacker may need new acts (for AI-realm response behaviors), but the dispatch model — one file per block type, filename stem = block type — is the same.
- **The four spec-level pillars** (CTN block format, flat stacks, flat typed blocks, URL = stack variable scope) hold. The AI Stacker may extend the URL pillar (intent expressions are not URLs) but does not violate the others.

This is the discipline: the AI-realm Stacker is a peer addition, not a redesign.

---

## What is new for the AI realm

In every existing Stacker, the **composition** of a page — which containers appear, in what order, with what args — is determined by a pre-authored stack file. The author lists containers; the Stacker invokes them in order; the resulting CTN block stream is dispatched through acts and rendered.

In the AI realm, the composition is determined at runtime by a language model interpreting user intent. The stack of containers for the current moment is assembled by the LLM in response to what the user is trying to do. The user does not navigate to a URL that selects a stack file; the user expresses an intent (typed, spoken, or implied by context), and the LLM summons the containers that serve it.

This is the move that defines the AI realm. Everything else is implication.

### Composition modes

A stack-of-containers in the AI realm can be assembled under different modes. Three are identified so far:

- **Fixed.** The containers in this region are pre-declared and do not change with intent. Equivalent to a 2015-style stack list. Used where predictability and muscle memory matter (navigation, persistent input affordances).
- **Constrained-fluid.** The containers in this region are selected by the LLM from a declared accept-list. The set is bounded; the order and presence are dynamic.
- **Open-fluid.** The containers in this region are selected by the LLM from the application's full container library. No accept-list. The LLM has delegated authorship of this region, working only from declared purpose.

A real surface mixes modes per region. A drafting surface might have fixed navigation, fixed input, and an open-fluid main area. The mode is declared per region.

### Regions

A surface composed by the AI Stacker has one or more named regions. Each region declares its composition mode and its rules — whether containers in it are persistent across turns, whether they are replaceable, whether they are pinnable by the user.

Regions are not a new architectural tier. They are how a single AI-realm surface declares its internal structure. The closest analogue in the existing architecture is the structure of a stack file's body, where containers appear in sequence — except that AI-realm regions have semantic identity (navigation, main, input, preview) and their own per-region composition rules.

### The intent → composition pipeline

A round-trip in the AI realm looks like this:

1. The user expresses intent (typed, spoken, or contextual).
2. The AI Stacker resolves intent against the surface's region rules and the application's container library.
3. The Stacker invokes the selected containers — same `$C->mod()` / `$C->ctn()` Core API as every other realm — with args derived from intent and context.
4. The resulting CTN block stream is dispatched through acts.
5. The response is rendered to the AI realm's output surface.

Step 1 replaces URL resolution. Step 2 replaces stack-file lookup. Steps 3–5 are unchanged from existing Stackers.

The substitution at steps 1–2 is what makes this a new realm. The continuity at steps 3–5 is what makes this a Containerist Stacker rather than a parallel system.

### Confirmation for consequential actions

Some actions have consequences: sending a message, deleting data, completing a purchase, applying an irreversible change. For these, the AI Stacker introduces a **confirmation step** between intent and execution. The system shows what it understood and what it will do. The user confirms or redirects.

Confirmation is not a permission gate for showing UI elements. Summoning a container is not consequential — the user can engage with it or ignore it. Trust accrues from the system summoning the right things at the right time, not from asking permission to render.

Confirmation belongs to acts that perform consequential effects. Mechanically, this is likely a new act (or family of acts) at the Stacker layer: a `confirm` act that gates the execution of certain other acts. The shape is unsolved.

### Pinning

A user may declare that a specific container in a fluid region should stay regardless of what the LLM would otherwise summon. This is **pinning** — a user-driven override of fluid placement, converting a container in that region from dynamic to fixed for that user.

Pinning is region-aware: it only makes sense in regions that allow it. A region's declaration includes whether pinning is permitted.

### Displacement, not designed fading

When a turn passes and the LLM summons a different set of containers for a new intent, prior containers do not perform a designed disappearance (fade, decay timer, "designed forgetting"). They are displaced by relevance: the surface reflects what is currently relevant, and what is no longer relevant is no longer summoned.

This is honest about what is happening. The system does not theatrically forget; it simply stops summoning what no longer fits. Pinned containers survive displacement.

---

## Vocabulary

Terms used in this document with specific meanings. Substrate terms (CTN block, container, mod, stack, Stacker, skin, act, `@in`, `@args`) are defined in `containerist.md` and are not redefined here.

- **AI-realm Stacker.** A new Stacker, peer to the Web Stacker and CLI Stacker, that composes CTN blocks under LLM direction interpreting user intent.
- **Region.** A named area within an AI-realm surface with its own composition mode and rules.
- **Composition mode.** The rule governing how containers enter a region: fixed, constrained-fluid, open-fluid.
- **Intent.** A user's expressed or inferred goal. The input to the AI-realm Stacker that replaces URL-based stack selection.
- **Intent descriptor.** Metadata on a container declaring what user intentions it serves. Used by the LLM to match containers to intent.
- **Confirmation step.** A user-visible check between intent and execution of a consequential action. Implemented as one or more acts.
- **Pinning.** A user-driven override making a container in a fluid region stable for that user.
- **Displacement.** The mechanism by which old containers leave the surface — by no longer being summoned, not by designed disappearance.
- **Intentionstreue.** Intent fidelity. The system does what the user meant, not just what they said, and shows its understanding before acting (especially for consequential actions). The organizing principle of the AI realm.

---

## What this must not become

Naming the drift directions explicitly, so they can be rejected by reflex:

- **A redesign of the substrate.** CTN, mods, stacks, skins, acts are not up for revision. If a proposed feature requires changing them, that is a separate spec discussion at the substrate level, not part of the AI-realm Stacker.
- **A second framework.** This is one Stacker among several. It does not impose its own runtime, its own component model, its own state management on consuming applications.
- **A chatbot with cards.** The conversation surface is the entry point for intent. The product is intentionstreue — the right things summoned at the right time.
- **A component library with semantic tags.** Containers (mods and static files) are already the unit. They gain intent descriptors. They do not become a new format.
- **A full agent framework.** Agents act in the world. This Stacker composes interactive surfaces in response to intent. Action is performed by mods invoked through Core, gated by confirmation for consequential cases. Out-of-band agentic behavior is out of scope.
- **A memory product.** Memory of user history is a design variable for trust calibration and intent matching, not a feature. The product is the trustworthy assembly of surfaces.
- **A system that imposes one composition mode on all regions.** The mode is per-region. Forcing everything fluid is as wrong as forcing everything fixed. The mix is the design surface.
- **A system tied to one implementation language.** Like the Web Stacker and CLI Stacker, the AI Stacker is realized in some implementation language (likely Python or TS given the LLM ecosystem) but is specified independently of any particular one. The wire-level invariants are language-neutral.

If a proposed feature pushes the system toward any of these, it is drift. Reject it or argue explicitly for the constraint to be lifted.

---

## What is unsolved

These are the open questions. A future LLM working on this should not pretend they are settled. Resolved questions get moved to the **Open questions log** below with their resolution recorded.

**Intent expression format.** What does intent look like on the wire as the input to the AI Stacker? A string? A structured object? A stream? The Web Stacker has URLs and HTTP request bodies; the CLI Stacker has argv and stdin. The AI Stacker needs a defined intent input shape. This is the equivalent of the URL → stack resolution step.

**Surface declaration format.** How is an AI-realm surface declared? A new file type? An extension of stack files via frontmatter? A CTN block type? The substrate avoids new file formats where possible; the surface declaration may need to be a CTN document of a new type (`CTN: ai-surface` or similar), keeping the wire format universal.

**Region grammar.** The properties of a region — mode, pinnability, persistence, accept-list — need a declared schema. Open questions: are regions nestable? Can a region inherit defaults from its surface? How are mode-specific properties scoped?

**Intent descriptor schema.** What does intent metadata on a container look like? At the CTN-spec level, additional fields in the container's CTN frontmatter? At the mod level, a new declared header alongside `@in`? The schema must be expressive enough for reliable LLM matching, structured enough for fast scanning, and consistent enough that the container library is navigable.

**The grammar of composition.** Two containers may both match the current intent but conflict when rendered together. How is this declared? How is it resolved at assembly time? Likely needs a small constraint language alongside intent descriptors, but the shape is unclear.

**Open-fluid library scale.** Open-fluid regions summon from the application's full container library. There is some scale at which the library becomes too large for the LLM to scan reliably. The mitigation strategies — pre-filtering by region accept-rules, intent-descriptor indexing, hierarchical libraries — are unexplored.

**Confirmation act shape.** Confirmation must be a Stacker-layer concept (acts are Stacker layer; mods cannot know about confirmation). The mechanics — does a mod emit a `CTN: confirm` block? Does the Stacker introspect emitted blocks for consequential acts? Is confirmation a wrapping act that gates other acts? — are unsolved.

**Trust calibration.** A new user benefits from explicit confirmation steps that an experienced user finds patronizing. The system must read this. Signals: memory of past confirmations, explicit user preference, time-of-day, action class. The blend is unsolved.

**Pinning interaction.** How does a user pin a container? A gesture? A command? An explicit UI element on the container itself? This is a real interaction-design problem, not solved by declaring that pinning exists.

**Designed forgetting (at the Stacker level).** The AI Stacker holds state across turns within a session. What it keeps, for how long, and what it deliberately forgets are real design questions. "Forgetting as a feature" is theatrical; "displacement by relevance" is honest. The state-management discipline that implements displacement is still to be designed.

**Bland-content drift.** The 2015 containerist critique: a unit that must work in many places tends to be written for none. In the AI age this is sharper because the LLM may summon a container into contexts the author never imagined. Variant systems, context-aware rendering, intent-scoped overrides are partial answers.

**Cross-realm mod invocation.** A mod written for the Web Stacker (PHP) can be invoked by another PHP-based Stacker in-process. Can the AI Stacker, likely in a different implementation language, invoke it across processes? This is the federation question for mods, distinct from the existing federation for CTN content.

---

## How to build this

The AI-realm Stacker should be built following both the Containerist pillars and the *Manifesto for LLM-Maintained Code*. Specifically:

- **Pillar 8 (Core is realm-free) holds.** Realm-specific code lives in the Stacker. Core does not learn about intent, regions, or confirmation.
- **Pillar 6 (uniform invocation) extends.** A mod invoked from the AI Stacker uses the same `$C->mod()` API, the same `@in` contract, the same CTN output as from any other Stacker.
- **Act dispatch is the extension mechanism for new realm behaviors.** Confirmation, region updates, intent acknowledgment — all are likely new acts at the Stacker layer, not new core code.
- **Container intent descriptors are additive metadata.** They extend the CTN frontmatter schema for containers but do not change the CTN-spec at the framing level.
- **The Stacker has a briefing document.** Following manifesto Point 10, the AI Stacker maintains its own operator briefing, updated as the architecture evolves.

The implementation language follows from practical concerns (likely Python or TS given the LLM ecosystem). The architecture is language-neutral; the implementation is not.

---

## What success looks like

A user opens an application served by the AI-realm Stacker. They express an intent — by typing, speaking, or by the context of the session. The system summons what is needed or useful: content to read, controls to act with, references to consult. Where the surface declares regions, the right containers appear in the right regions. Consequential actions are confirmed in plain language before they happen. Everything else simply appears when relevant and gives way when something else is more relevant. The user can pin anything that should stay regardless. Across many turns, the user develops trust not because the layout is predictable, but because the system summons the right things at the right time and asks before doing what matters.

That is the goal. Everything else is implementation.

---

## Open questions log

Append-only log of questions raised in sessions, with status. When a question from **What is unsolved** is resolved, move it here with its resolution.

*(Empty at first draft. Future entries: date, question, status (open / resolved / deferred), resolution or notes.)*

---

*End of document.*
