# Containerist

**Construct web sites from stackable containers.**

A web page is a flat list of autonomous, self-sufficient **containers**. Each container has a public URL (its *origin*), reveals a machine-readable **structure** (CTN), and is drawn through a consumer-chosen **skin**. Containers can be *federated* — shown on any site, in any stack, served from a different origin than the page displaying them — while authorship stays with the origin's author.

Containerist is a small, portable framework built on that idea, designed to stay legible to both humans and the LLMs that increasingly maintain it. This repository is the **specification and hub**: the canonical formats, the design rationale, and the map to the three implementations.

---

## New here? Start with the story

Read these in order — no code required:

1. **[docs/mission.md](docs/mission.md)** — *what Containerist is for.* The six principles, the two imperatives, and the "digital home" idea behind it all.
2. **[docs/containerist.md](docs/containerist.md)** — *what Containerist is.* The operator briefing: the engineering pillars and how a site is actually built.
3. **[docs/compared-to.md](docs/compared-to.md)** — *why not just use React/Next/WordPress?* The specific tradeoff you're choosing.
4. **[docs/briefing-detailed.md](docs/briefing-detailed.md)** — the long-form deep dive when you want the full rationale.

Then pick an implementation below and read its README.

## Building on it? Go to the specs

The formats are the authority — no single implementation owns them. Conform to these and you're a Containerist:

| Spec | What it defines |
|---|---|
| **[spec/CTN-SPEC.md](spec/CTN-SPEC.md)** | CTN — the portable container structure (YAML frontmatter + body) |
| **[spec/IN-SPEC.md](spec/IN-SPEC.md)** | `@in` — the container input contract |
| **[spec/PLACE-SPEC.md](spec/PLACE-SPEC.md)** | the place format — URL → surface resolution, `@args`, `@federation` |
| **[spec/ACTS-SPEC.md](spec/ACTS-SPEC.md)** | sessions, auth facts, and the POST → redirect → GET lifecycle |
| **[spec/FEDERATION-SPEC.md](spec/FEDERATION-SPEC.md)** | cross-origin containers: transport, trust, error model |
| **[spec/WIRE-SPEC.md](spec/WIRE-SPEC.md)** | the optional CTN-over-HTTP adapter |

Per-language notes live in **[spec/implementations/](spec/implementations/)** (`CTN-in-PHP.md`, `CTN-in-TS.md`, `CTN-in-Go.md`, and the rest). Language-agnostic conformance fixtures are in **[conformance/](conformance/)** — pass them and your parser is conformant on the enumerated edge cases.

## The three implementations

Each is a faithful realization of the specs above. **The PHP implementation is the reference — it leads; the others follow.**

| Language | Repo | Stack |
|---|---|---|
| **PHP** | [containerist-php](https://github.com/containerist/containerist-php) | Reference implementation |
| **TypeScript** | [containerist-ts](https://github.com/containerist/containerist-ts) | Next.js / React |
| **Go** | [containerist-go](https://github.com/containerist/containerist-go) | Native Go |

---

## Repository map

```
docs/            Understand Containerist (prose, no code)
  mission.md         why it exists — principles & imperatives
  containerist.md    the operator briefing — what it is, how to build
  briefing-detailed.md   full architectural rationale
  compared-to.md     positioning vs. other frameworks
  direction.md       roadmap — named intents, costs, risks
  essays/            longer-form vision pieces
  manifesto-llm-first/   the LLM-first maintenance philosophy
spec/            The canonical formats (authority)
  *-SPEC.md          the six specs
  implementations/   per-language implementation guides
conformance/     Language-agnostic CTN test fixtures
releases/        MIGRATION.md and per-version release notes
archive/         Retired formats kept for porting (4.x stack format)
tools/           Spec-side tooling (e.g. the CTN lint)
CHANGELOG.md     The version arc
```

## Philosophy in one paragraph

Containerist optimizes for **statelessness on both sides of the keyboard**: a human returning after months, and an LLM starting every task from zero. Explicit inputs, flat namespaces, thin entry points, no hidden wiring. If you can't reconstruct how the system works from one file, you pay for the missing pieces in search. See **[docs/manifesto-llm-first/](docs/manifesto-llm-first/)**.

## License

Licensed under the **GNU Affero General Public License v3.0** (AGPL-3.0) — see [LICENSE](LICENSE).

The AGPL is deliberate: because containers and modules are built against and loaded into the framework, work that extends Containerist and is served to users over a network is covered by the same copyleft. **Build on Containerist and put it in front of users, and that work is meant to be open source too.** That is the intent, not an accident.

Copyright © 2026 Konstantin Weiss.
