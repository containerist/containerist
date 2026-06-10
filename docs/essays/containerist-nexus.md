# Containerist with Nexus

_A substrate architecture for LLM-driven interfaces_

**Author:** Konstantin Weiss  
**Written by:** Claude Opus 4.7 and ChatGPT 5.4
**Date:** 2026-05-12  
**Version:** 0.8  
**Status:** Draft  

--

## TL;DR:

The framework is basically built around:

- flat typed blocks
- ordered invocation
- explicit inputs
- renderer separation

That's it. It's tiny. It features:

- stream-native composition
- declarative composition boundaries
- composer neutrality
- isolated invocation contracts
- local rendering over remote execution
- visible interpretation before action

The claim: 

> Composition is a substrate concern, not an AI overlay.

--

## Abstract

Most LLM-driven interfaces are layered onto substrates that assumed a human developer was the only composer. React trees, action registries, chat surfaces, and tool schemas are being adapted after the fact to support inferred composition.

This paper describes a different starting point.

**Containerist** is a specification for composing interfaces from flat typed blocks. The substrate consists of five normative documents governing block structure, mod inputs, composition, acts, and federation. It currently has three implementations and several public deployments exchanging content across origins.

**Nexus** is a proof-of-concept LLM composer added to one Containerist instance. It composes surfaces using the same mod inventory, dispatch rules, and rendering pipeline already used by deterministic composition.

The paper makes one strong claim and one weaker claim.

The strong claim: an LLM composer becomes structurally simpler when the substrate enforces two constraints simultaneously:

1. interface output is a flat stream of typed blocks;
2. mods cannot access the request directly and operate only through declared inputs.

Those two constraints make composers peers. URL routing, CLI calls, federation fetches, and LLM selection all reduce to ordered mod invocation over the same dispatch pipeline.

The weaker claim: when this substrate is combined with federation, interface composition no longer has to terminate at a single application boundary. The architecture admits cross-origin composition where surfaces, mods, composers, and skins may originate from different systems while remaining interoperable through the same wire format.

The substrate exists. The LLM composer exists as a single-instance proof of concept. The broader distributed implication remains speculative.

--

## 1. The problem

Current LLM-driven interfaces usually fall into one of three patterns.

### Chat with generated components

A chat thread acts as the primary surface. The model emits structured tool calls which render components into the conversation.

Examples include:

* generative UI systems built on React;
* AI SDK component registries;
* assistant canvases;
* adaptive reply interfaces.

### Deterministic application with AI overlay

The application remains fundamentally deterministic. The LLM operates through a side panel, assistant region, or action layer.

Examples include:

* Copilot-style assistants;
* AI sidebars in editors;
* action registries over application state.

### Tool-oriented chat clients

The interface is primarily conversational. External tools are callable, but rendering semantics remain implicit.

Examples include:

* MCP hosts;
* Claude Desktop;
* Cursor-style tool environments.

These systems differ operationally but share the same architectural assumption:

> the substrate underneath the interface was designed before inferred composition existed.

The result is structural asymmetry.

Deterministic composition and inferred composition become separate systems connected through orchestration glue:

* one abstraction for developers;
* another abstraction for models;
* adapters between them.

This works operationally. It also creates friction:

* renderers tied to one composer;
* tool systems coupled to framework primitives;
* streaming output fighting tree-shaped structures;
* composition logic spread across orchestration layers;
* cross-implementation portability becoming difficult.

The architectural question is therefore not:

> how do we add AI to the interface?

The question is:

> what does the substrate look like if inferred composition is treated as native from the beginning?

This paper argues that the answer requires three things:

1. a stream-native wire format;
2. a uniform invocation model;
3. composition declared independently of any one composer.

--

## 2. The interaction shift

Human-computer interaction evolves by layering new composition systems onto older ones.

The command line did not disappear when graphical interfaces arrived.

Graphical interfaces did not eliminate command lines.

Conversational systems will not eliminate either.

Each layer solves a different problem.

| Layer | Strength                     | Weakness                      |
| ----- | ---------------------------- | ----------------------------- |
| CLI   | open composition             | high cognitive cost           |
| GUI   | stable affordances           | constrained possibility space |
| CUI   | open-ended intent expression | unstable interface structure  |

The important historical pattern is coexistence.

A modern interface needs both:

* stable regions with predictable spatial behavior;
* fluid regions capable of inferred composition.

Most current systems treat this as a feature problem:

* add a sidebar;
* add a chat window;
* add generated widgets.

This paper treats it as a substrate problem.

The substrate must support multiple composers at the same structural level.

That requirement changes three things.

### Composition becomes regional

Different parts of the same product need different composition rules.

Navigation wants stability.

Exploration benefits from inference.

Reference material benefits from fluidity.

Editing surfaces benefit from partial persistence.

The correct boundary is therefore not per application.

The correct boundary is per region.

### Trust shifts away from spatial stability

Traditional interfaces build trust through consistency:

* fixed locations;
* repeated affordances;
* stable layouts.

Fluid interfaces cannot rely entirely on those mechanisms.

Trust instead depends on whether the system demonstrates an accurate understanding of intent before acting.

That produces three requirements:

1. the system exposes its interpretation before execution;
2. the system can refuse composition cleanly;
3. the user can correct the interpretation before commitment.

These are not primarily model questions.

They are substrate questions.

### Ephemerality becomes negotiated

In a fluid interface, elements appear and disappear according to context.

Users still need anchors.

The system therefore cannot fully control persistence.

Users must be able to stabilize specific regions or elements.

The interface becomes jointly composed:

* the system proposes;
* the user fixes;
* the substrate mediates both.

--

## 3. Containerist

Containerist is not primarily a framework.

It is a specification.

The substrate consists of five normative documents.

| Spec            | Responsibility            |
| --------------- | ------------------------- |
| CTN-SPEC        | block wire format         |
| STACK-SPEC      | deterministic composition |
| IN-SPEC         | mod input contracts       |
| ACTS-SPEC       | dispatch behavior         |
| FEDERATION-SPEC | cross-origin transclusion |

The implementations follow the specification rather than defining it.

### Implementations

| Implementation | Status            |
| -------------- | ----------------- |
| PHP            | mature            |
| TypeScript     | operational       |
| Go             | partial / ongoing |

The existence of multiple implementations matters because the architecture depends on portability.

A substrate that only survives inside one framework is not a substrate.

### Public deployments

Several public instances already exchange CTN across origins.

These deployments were important because federation pressure exposed weaknesses in the specification:

* YAML normalization edge cases;
* parser assumptions;
* size constraints;
* rebasing semantics.

The specification evolved through interoperability failures rather than purely local design.

--

## 4. CTN

Container Text Notation is the substrate wire format.

A CTN stream is a sequence of flat typed blocks.

```text
  CTN: article
  title: "Flat composition"
  --
  Body content.

  CTN: related
  ids: [a, b, c]
```

There is no document tree.

There is only an ordered sequence.

### Why flatness matters

Flatness produces four properties that matter for inferred composition.

#### Concatenation safety

Two valid streams can be appended together without transformation.

#### Incremental parsing

Streaming parsers can dispatch blocks before the full response exists.

#### Renderer independence

Blocks are rendered independently by type.

#### Composer neutrality

The wire format does not encode who composed the stream.

A URL router, CLI call, federation fetch, or LLM can all produce identical artifacts.

### Why trees become difficult

Tree-oriented interfaces assume structural knowledge ahead of time.

Streaming inferred composition violates that assumption.

A model emits partial structures incrementally.

Tree systems therefore accumulate:

* placeholder nodes;
* partial hydration logic;
* schema orchestration;
* state synchronization layers.

Flat streams avoid most of this complexity because ordering is sufficient.

The renderer does not need to know future structure.

--

## 5. Mods

A mod is a typed producer.

```php
  <?php
  // @in: id (required)
  
  $article = load_article($id);
  
   echo "CTN: article\n";
  echo "title: " . yaml_quote($article->title) . "\n";
  echo "---\n";
  echo $article->body;
```

Mods are constrained intentionally.

They do not access the request directly.

They do not access ambient session state.

They receive only declared inputs.

That single constraint is the most important architectural decision in the system.

### The real consequence

Most systems claim some form of uniform invocation.

Few enforce it structurally.

Components eventually accumulate dependencies on:

* request objects;
* framework globals;
* dependency injection;
* hidden state;
* runtime-specific APIs.

Once that happens, composers stop being peers.

Different composers require different adapters.

Containerist prevents this by removing request access from the Core entirely.

A mod becomes:

> a pure function of declared inputs.

That makes the same mod invocable from:

* HTTP;
* CLI;
* federation;
* deterministic composition;
* inferred composition.

without modification.

--

## 6. Composition

Containerist separates composition from rendering.

A composer produces an ordered set of mod invocations.

The Core executes them.

The dispatch pipeline renders the resulting blocks.

Different composers therefore become interchangeable.

### Deterministic composition

A stack file:

```text
  CTN: stack
  @args: id = {{2}}
  --
  page-header
  article-detail?id={{id}}
  related?id={{id}}
```

The semantics are deliberately minimal:

* ordered execution;
* no nesting;
* no conditionals;
* no hidden orchestration.

### Acts

Acts consume blocks.

Each type may define:

* rendering behavior;
* redirects;
* headers;
* response mutations.

The default act is skin rendering.

### Realm separation

The Core remains realm-free.

Adapters adapt specific environments:

* Web;
* CLI;
* AI;
* future realms.

The important boundary is architectural:

> the Core does not know who composed the stream.

--

## 7. Nexus

Nexus is a proof-of-concept inferred composer, powered by an LLM.

It operates as a peer adapter.

It uses:

* the same mods;
* the same dispatch system;
* the same skins;
* the same block format.

Only the composition rule changes.

### Place files

A place file declares regions.

```yaml
editor:
  mode: fixed
  containers: [draft-editor]

tools:
  mode: constrained-fluid
  containers:
    - search
    - related
    - references

main:
  mode: open-fluid
```

### Region modes

#### Fixed

Deterministic composition.

#### Constrained-fluid

The composer selects from a bounded candidate set.

#### Open-fluid

The composer selects from the full inventory.

The important point is not the modes themselves.

The important point is that the composition spectrum is declared structurally rather than hidden in orchestration code.

--

## 8. Streaming inferred composition

The Nexus prompt contains:

* region purpose;
* candidate inventory;
* mod input contracts;
* output rules.

The model emits CTN directly.

```text
  CTN: ai-selection
  interpretation: "You want revision tools for the cyberpunk draft."

  CTN: candidate
  name: related
  include: yes
  args:
    id: cyberpunk
```

The stream is parsed incrementally.

Blocks dispatch as they arrive.

Composition itself becomes visible.

### Why this matters

Most interfaces treat loading as absence.

The user waits while hidden orchestration occurs.

Here, composition is exposed:

* interpretation arrives first;
* picks stream live;
* execution follows.

The interface therefore exposes reasoning structure before action.

This is not a guarantee of correctness.

It is a correction surface.

The distinction matters.

A visible interpretation without correction capability is performance.

A visible interpretation with interruption and correction becomes operationally useful.

### Refusal as a valid state

The composer may also refuse.

That is important.

Most orchestration systems implicitly force composition.

Nexus allows:

* no pick;
* uncertainty;
* clarification.

The architecture treats absence as valid output.

--

## 9. Federation

Federation allows one instance to inline CTN produced elsewhere.  
The producer returns CTN.  
The consumer renders it locally.  
Remote code never executes on the consumer.  
This distinction is critical.  
Federation distributes content, not execution.

### Operational observations

Real deployments surfaced several properties.

#### Size pressure

Large producer responses approached the advisory limit quickly.

The federation specification was revised accordingly.

#### Failure isolation

Federation failures degrade locally.

A failed producer becomes an inline error block.

The page itself remains valid.

#### Trust boundary

Federation is allowlist-based.

No wildcard trust.

No ambient remote execution.

The consumer always mediates rendering.

--

## 10. The architectural implication

The substrate already supports:

* flat typed streaming;
* peer composers;
* portable mods;
* federation.

When those properties coexist, the application boundary weakens.

A different structure becomes possible.

### The emerging shape

A surface may:

* originate at one system;
* invoke mods from another;
* compose through a third;
* render through the consumer's skins.

Composition stops being tightly coupled to one deployment.

The substrate begins behaving more like a protocol layer.

This paper does not claim that this future already exists.

It does not.

What exists today:

* deployed federation;
* deployed multi-implementation substrate;
* single-instance inferred composer.

The broader implication remains speculative.

Still, the architectural direction is visible.

The important point is not whether Containerist becomes the dominant implementation.

The important point is whether the underlying shape appears elsewhere.

--

## 11. Limits

The architecture solves specific problems.

It does not solve all interface problems.

### Flat streams lose locality

Tree structures encode ownership and dependency naturally.

Flat streams trade some of that locality away.

That creates pressure around:

* transactional consistency;
* collaborative editing;
* nested reactive graphs;
* concurrent synchronization.

The substrate currently favors:

* streaming;
* federation;
* inferred composition;
* renderer independence.

Whether it can absorb locality-heavy interaction models later remains unresolved.

### Mods remain environmentally coupled

Removing framework coupling does not remove environmental assumptions.

Mods still depend on:

* data structures;
* latency assumptions;
* caching behavior;
* authentication context;
* side-effect semantics.

Portability therefore has limits.

The architecture guarantees:

* invocation portability.

It does not guarantee:

* operational portability.

### Open-fluid scaling

The current inferred composer enumerates candidate inventories directly inside prompts.

This works at small scale.

It does not scale indefinitely.

Candidate retrieval, indexing, ranking, and permissioning remain unresolved.

### Memory remains largely undesigned

The substrate currently has no mature answer for:

* long-term memory;
* forgetting policies;
* cross-origin memory;
* persistence scope.

The interaction consequences are substantial.

Fluid interfaces become unreliable without memory.

They become dangerous with uncontrolled memory.

--

## 12. Comparison

| Pattern                  | Primary substrate         | Composition model                                     |
| ------------------------ | ------------------------- | ----------------------------------------------------- |
| Generative React UI      | component tree            | inferred composition embedded into deterministic tree |
| AI sidebars and coagents | deterministic application | assistant layered over existing state graph           |
| MCP clients              | conversational surface    | rendering delegated to host                           |
| Containerist             | flat typed stream         | composers treated as peers                            |

The difference is not that Containerist contains an LLM.

Many systems do.

The difference is where the composer sits architecturally.

Most systems adapt existing deterministic substrates.

Containerist redesigns the substrate around composition neutrality.

Whether that trade-off is correct depends on whether future interfaces are primarily:

* application-centric;
* or composition-centric.

--

## 13. What matters beyond this project

The paper is not fundamentally about Containerist.

The useful questions are architectural.

#### 1. Is the wire format stream-native?

Can output be parsed incrementally and concatenated safely?

#### 2. Are composers peers?

Can a new composer be added without rewriting renderers?

#### 3. Is invocation uniform?

Can the same component operate across deterministic and inferred composition without adapters?

#### 4. Is the composition spectrum explicit?

Are fluid and fixed regions declared structurally?

#### 5. Can composition cross origins?

Not just content distribution.

Composition itself.

#### 6. Does the interface expose interpretation before action?

And can the user correct it?

#### 7. Who controls persistence?

The system alone?

Or the user as well?

#### 8. Is memory treated as infrastructure or design?

Because fluid systems eventually force that distinction.

--

## Conclusion

The paper makes a limited argument.

LLM-driven interfaces become structurally simpler when:

* composition is represented as a flat typed stream;
* invocation is uniform across composers;
* mods are isolated from request context;
* composition rules are declared independently of any single runtime.

Containerist demonstrates one implementation of those constraints.

Nexus demonstrates that an inferred composer can operate within them without forking the substrate.

The broader distributed implication remains unresolved.

The important result is therefore not the product.

It is the architectural shape:

* flat streams instead of trees;
* peer composers instead of overlays;
* protocol-oriented composition instead of application-bound rendering.

Whether this specific implementation survives is secondary.

The substrate direction is the real claim.
