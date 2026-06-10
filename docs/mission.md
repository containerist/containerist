# Containerist — Mission

document_version: 1.1
date: 2026-04-22

*The foundational statement. The six principles and two imperatives that motivate everything else in this repository. Briefing, pillars, specs, and implementations are tools for realizing what is stated here.*

*If `docs/containerist.md` describes what Containerist IS, this document describes what Containerist is FOR.*

---

## Origin

Containerist is the technical realization of an idea the author first articulated in 2010 as **"digitale Heimat"** (digital home) — a psychological need, not a product thesis. The original essay, [*Sehnsucht nach digitaler Heimat*](http://konnexus.net/n/K100716A-sehnsucht-nach-digitaler-heimat) (*Longing for Digital Home*, konnexus.net, 2010), proposed three commitments for a digital life that serves the self rather than platforms:

1. **Mein Inhalt ist mein Inhalt.** My content is mine, at all times, in my control.
2. **So wenig Code zwischen mir und meinem Inhalt wie möglich.** As little code as possible between me and my content.
3. **Verschlichtere Dich.** Voluntarily forgo complexity for simplicity.

The essay draws a parallel between psychological *Heimat* — a place of inner return, independent of others, earned through effort — and its digital equivalent: an anchored, sustainable, self-sovereign presence on the web, maintained through the same discipline one applies to body and nature.

Every pillar in `docs/containerist.md` and every principle below is an attempt to realize these three commitments in working code. The framework is a tool in service of a personal practice; the practice is older than the framework by more than a decade and will outlast any specific implementation.

Containerist is not a product. Not a market play. Not a movement. It is **Heimat-architecture** — for the one person who needs it, and for any others who recognize the longing.

---

## Foundational statement

**Construct web sites from stackable containers.**

A web page is a stack of autonomous containers.

Content on web pages is enclosed in these containers. Every container has full layout width. It can be further structured within itself, but is self-sufficient. Content and interactions in a container do not refer to previous or following containers.

Containers of one stack may be loaded from origins anywhere on the web.

Every container has a public URL — its **origin**. A page is defined merely by a list of those origins. From there, containers are loaded into the page. The container's author maintains the original. The authors of the stack and of the container need not be the same.

---

## The six principles

### Principle 1 — STACK

You can stack as many containers as you wish.

### Principle 2 — POSITION

A container can be positioned at any place within the stack.

### Principle 3 — REPEAT

You can place more than one container of the same type into the same stack.

### Principle 4 — CONDITION

Containers can have conditions. If those are met, the container is displayed. Conditions can refer to space, time, user, etc.

*Realization.* CONDITION is not a separate mechanism. It is realized through the input contract (`@in`) and the adapter's per-realm wiring: the adapter populates a container's inputs from whatever the realm knows (URL, session, auth, time, device, geo, role); the container reads those inputs and decides its own visibility. Out-of-context containers emit empty. No new syntax, no new block type. See `docs/containerist.md` pillar 5 and `IN-SPEC.md`.

### Principle 5 — REUSE

The same container can appear in many stacks. In each stack, the position is free to choose.

*Realization.* Transclusion — the same container reappearing across multiple stacks, URLs, and CLI calls, resolved at invocation time from data (not import-resolved at build time from code) — emerges from the combination of pillars 2 (stacks as flat text), 6 (uniform invocation across realms), and 7 (namespace-flat containers). See `briefing-detailed.md` for the full contrast with component-tree frameworks.

### Principle 6 — FEDERATION

The container can be displayed on any site, in any stack. The source can come from a different site than the displayed stack's site.

*Realization.* See `FEDERATION-SPEC.md` for the transport, trust, and error model. The MVP implementation targets `containerist-php` with stitchson.net consuming konnexus.net as the initial forcing function.

---

## The two imperatives

The principles, especially Principle 6, depend on two non-negotiable conditions.

### Imperative I — ACCESS

The origin and structure must be public and accessible.

Without public, accessible origins there is no federation; without federation Principle 6 collapses and the mission is incomplete. A container served behind auth, paywall, or region-block may still be a container — but it has excluded itself from the federated layer.

### Imperative II — AUTHORSHIP

The structure remains in the hands of the author.

The consumer renders; the author authors. A stack displaying a federated container does not own, mirror, or fork that container's structure. When the author changes the source, the consumer sees the change on the next cache cycle. No copies. No drift. No silent takeover.

Imperative II is what makes federation meaningful rather than a distribution channel: the author retains authority over what they emit. Consumers participate by reference, not by copy.

---

## Containerist anatomy

For federated containers to work, they must have a specific anatomy.

### Origin

Every container has a URL — its **origin**. The origin must be available and accessible to anyone (Imperative I).

### Skin

Every container type has a front-end — HTML, CSS, JS — that shows the container to the user and enables interaction. The **skin** is often a template. The skin is the consumer's concern: the same container from one origin may be rendered with different skins on different sites or devices.

### Structure

Before a skin is rendered, the container must reveal its **structure** — its data and interaction possibilities in a machine-readable form. The structure is as skin-independent and as structured as possible.

The structure must be revealed and provided. Then, the container can be skinned differently depending on the site and the device. The structure and a skin template are rendered together to produce the displayed container.

In Containerist, the **structure** is CTN (see `CTN-SPEC.md`), the **skin** is the `skin/<type>.{html,css}` pair (see `docs/containerist.md` pillar 3), and the **origin** is the URL at which the producer serves the CTN (see pillar 6 and `FEDERATION-SPEC.md`).

---

## Why this document exists

Much of what is captured here was, for a period, implicit — distributed across the engineering pillars in `docs/containerist.md`, the spec files (`CTN-SPEC`, `IN-SPEC`, `STACK-SPEC`), and the author's memory. That was a mistake. The pillars are the **how**; the principles are the **what**; the simplicities are the **why**. All three must be written down or the project drifts.

Read the pillars in the briefing to understand how Containerist builds. Read this document to understand what Containerist builds **for**, and what it refuses to compromise on.

When a future decision in `docs/containerist.md`, a spec, or an implementation conflicts with a principle or imperative here, this document wins — or the principle is revised deliberately, in writing, with rationale. Silent drift from the principles is not acceptable.

---

*Amendments to principles or imperatives require deliberate revision with recorded rationale — not incidental change.*
