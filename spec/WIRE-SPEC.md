# WIRE — CTN-over-HTTP Adapter Specification

**Version 0.1 (draft)**
**Status:** Optional adapter spec. An implementation MAY provide a wire adapter; one that does MUST conform to this document. Extracted from the Go reference implementation (`containerist-go/adapters/spa`, shipped 5.2-line) and its first consumers (the rook-flasher instance: the `flasher-rich` browser client, the on-device screen pipeline, and plain `curl`). PHP and TS reference implementations do not provide a wire adapter at this revision; that is a conformance-table fact, not a gap to close before this spec is useful.
**Date:** 2026-06-10

*Scope: the **wire adapter** is the third realm adapter beside web and cli. Where the web adapter performs the realm-neutral response as HTML (skins, acts, page-wrap, auto-PRG) and the cli adapter performs it as stdout + exit code, the wire adapter performs it as **CTN itself**: every response body is the typed-block text format, `text/ctn`. It is the hourglass waist exposed as a product surface — for rich clients, device renderers, scripts, and any consumer that brings its own renderer. This spec defines the adapter's URL surface, its mutation semantics, its realization of the arg lifecycle, and its session/flash behavior. It changes nothing in the core specs: CTN-SPEC, PLACE-SPEC, IN-SPEC, and ACTS-SPEC are referenced normatively and are not modified by this document.*

*A note on numbers. As in FEDERATION-SPEC: specific numeric values here (session TTL, upload caps) are advisory starting points. Implementations MUST have the named mechanisms; the values are tuning guidance.*

**Companion specs.**
- `CTN-SPEC.md` — the wire format every response body is.
- `PLACE-SPEC.md` — place files, resolution, `@args` bindings; the structure the `.spec` endpoint projects.
- `IN-SPEC.md` — the input contract; undeclared args are filtered before the mod body runs, which makes §7's merge order safe.
- `ACTS-SPEC.md` — the arg lifecycle (§6) this adapter realizes positions 4–6 of, and the `flash` semantics (§5.6) the PRG path stashes into.
- `containerist.md` — operator briefing.

**Revision trail.**
- *0.1 draft (2026-06-10).* Initial extraction. The implementation predates the spec (the Go adapter shipped with the rook-flasher instance before this text existed); 0.1 documents the shipped contract rather than designing a new one, in the same move that produced CTN-SPEC from the PHP parser on 2026-04-20. Known deliberate exclusions are listed in §2 and §12.
- *0.1 draft, same-day amendment (2026-06-10).* The spec-vs-impl verification pass found one conformance bug in the Go reference (`_return` was forwarded to schema-less mods; fixed + pinned, see `WIRE-in-Go.md`) and three places where the spec text needed the precision the bug exposed: §7 now qualifies IN filtering as per-registration-mode and makes the adapter responsible for its own reservations regardless of schema filtering; §7 gains the realm-reservation cross-reference to ACTS-SPEC §6 (`q`/`trace`); §9.2 names the exact-phrase detection heuristic and bounds the MUST NOT to the merely-contains case. No surface or behavior change.

---

## §1 Purpose and position

The web and cli adapters ship a rendered artifact. The wire adapter ships the **waist**: the CTN stream a Core invocation produces, verbatim, over HTTP. A consumer of the wire is a peer renderer — a browser client with a block registry, a framebuffer pipeline, a shell script parsing typed blocks, another machine.

This is an **optional** adapter. A conformant Containerist implementation needs web and cli realizations of the response contract; it does not need a wire. The wire becomes worth providing when an instance has out-of-process renderers — a rich client, a device surface, programmatic consumers — that want structure and content as data rather than as HTML.

Two properties follow from "the waist exposed" and govern everything below:

1. **The wire is realm-neutral toward its consumers.** The same GET answers a browser fetch, a `curl`, and an embedded poller byte-identically. The adapter does not branch on who is asking — with one explicit, consumer-*opted* exception (§6).
2. **The wire carries no server-side act dispatch.** Acts are the renderer's block-type dispatch layer (ACTS-SPEC §1); the wire's renderers (§4, §5) relay blocks instead of dispatching them. Effects that the web adapter would perform server-side (redirect, flash display, title) are the consumer's to interpret — except the session-cookie mechanics the adapter itself owns (§8).

The wire is **not** a no-JS browser surface. A browser pointed at a `.ctn` URL sees typed plain text. Instances wanting a no-JS HTML surface provide it via the web adapter; the wire's contribution to constrained-device access is different — it lets a server-side pipeline (e.g. a framebuffer renderer) consume the same units a rich client does.

## §2 Scope and non-goals

### In scope for 0.1

- The suffix-routed URL surface: `.spec` (place structure), `.ctn` (container content/mutation), `.events` (reserved) — §3.
- The structure endpoint: a place projected as one `CTN: place` block, structure only — §4.
- The container endpoint: GET reads, POST mutations relayed verbatim (the P2 pattern) — §5.
- Opt-in Post-Redirect-Get for no-JS browser form posts (`_return`) — §6.
- The arg lifecycle on the wire: ACTS-SPEC positions 4–6, realized on every request — §7.
- Session cookie + flash semantics — §8.
- Error and status mapping — §9.

### Explicit non-goals for 0.1

- **Page-wrapping, skins, server-side act dispatch.** The web adapter's job. The wire relays blocks.
- **The suffix-zoo.** `.htmx`, `.raw`, `.placectn`, `.trace` are web-adapter inspection/fragment surfaces. The wire's surface is exactly §3's three suffixes.
- **Auto-PRG on place URLs** (ACTS-SPEC, 5.0+). The wire serves no place *pages*, only place *specs*; there is nothing to auto-PRG. The wire's PRG is the opt-in form in §6.
- **Serving the client bundle.** A deployment MAY co-mount a static handler beside the adapter (single-origin: adapter under a prefix such as `/api/`, bundle at `/`); the bundle, its fallback rules, and its caching are deployment concerns outside this spec.
- **Server-sent events.** `.events` is reserved and MUST answer `501 Not Implemented` until a future revision specifies it (§10).
- **Cross-origin policy.** CORS headers, if any, are deployment configuration. The default posture is same-origin.
- **Write-side federation, authenticated federation.** Unchanged from FEDERATION-SPEC; the wire adds no federation surface.

## §3 Routing

### §3.1 Prefix

The adapter answers under an optional configured **prefix** (e.g. `/api/`). The default is the empty prefix: the adapter owns every path (a pure-wire server). With a non-empty prefix, requests outside it are not the adapter's: it MUST answer 404 (or the deployment routes them elsewhere before the adapter sees them).

### §3.2 Suffix dispatch

After stripping the prefix, the path is split into segments; the **suffix** is the text after the last `.` in the last segment, and the segment list keeps the stem. Dispatch is by suffix:

| Suffix | Method | Handler |
|---|---|---|
| `.spec` | GET | place structure (§4) |
| `.ctn` | GET, POST | container content / mutation (§5) |
| `.events` | — | reserved; `501` (§10) |
| anything else | — | `404` |

There is no extensionless route. A place URL without a suffix is a web-adapter concept; on the wire, everything names its representation.

## §4 The structure endpoint — `GET /places{path}.spec`

### §4.1 Resolution

The first path segment MUST be the literal namespace marker `places`; the remaining segments are the navigation path, resolved to a place pattern by the standard priority-sorted wildcard match (PLACE-SPEC §4). `GET /places.spec` (empty navigation path) resolves the index place. No resolvable place → `404`. A headless instance (no places directory) → `404` for every `.spec` request.

### §4.2 Response

One `CTN: place` block, `text/ctn`, frontmatter only (no body). It describes the place's **structure** and composes nothing — the consumer fetches each container itself via §5. Fields, in emission order:

```
CTN: place
name: <resolved place pattern>
"@args": { <name>: <binding>, ... }     # the place's @args, when present
title: <title>                          # when the place declares one
columns:                                # when the place declares a column chain (§4.4)
  - <container-or-place ref>
regions:                                # declaration order
  - name: <region name>
    containers:
      - name: <container id>
        args: { <k>: <v>, ... }         # resolved per-line args, when present
        refresh: <duration>             # lifted refresh hint, when present (§4.3)
---
```

Per-line args are resolved against the place's `@args` bindings and the URL parts before emission — the consumer receives literal values, not `{{...}}` templates.

### §4.3 The `refresh` hint

A place author MAY express auto-refresh by adding a reserved per-line arg named `refresh` (e.g. `cartridge-status?refresh=2s`). The spec renderer MUST lift it out of the container's `args` into the distinct `refresh` field. It is a hint to the consumer's polling loop; it MUST NOT reach the mod as an input. `refresh` is thereby a **reserved arg name** on container refs served over the wire (the wire's sibling to the `q`/`trace` reservations named in ACTS-SPEC §6).

### §4.4 The `columns` field

A place MAY declare `columns:` — an ordered chain of refs a cascade-style consumer seeds its view from — beside or instead of `regions:`. The wire projects it verbatim. **Grammar caveat:** `columns:` is not yet part of PLACE-SPEC's place-file grammar; it is carried here as shipped behavior, and its promotion into PLACE-SPEC is an open question (§12). A consumer MUST tolerate its absence; a place with only `regions:` is the normal case.

## §5 The container endpoint — `GET|POST /{name}.ctn`

### §5.1 Namespace

`{name}` is the final path segment's stem, resolved against the flat container namespace (pillar 7): mod first, static container fallback. The dispatch is the same `Core.Ctn` every other adapter uses — uniform invocation is the point.

### §5.2 GET — read

The response body is the container's CTN, verbatim: zero or more blocks. An empty body is legal and meaningful (a self-suppressing unit). Content-Type `text/ctn; charset=utf-8` (§9.1).

### §5.3 POST — mutation, relayed verbatim

A POST invokes the mod with the merged args of §7 (the form/multipart body included) and relays the returned CTN **verbatim** — no server-side act dispatch, no automatic redirect, no flash display. The recommended mod shape is the **P2 pattern**: the mutation's output *describes what happened* (pillar 11) as a `CTN: flash` block, optionally followed by fresh content blocks (the mod re-sourcing the affected unit via the Core) so the consumer can update its view from the response alone:

```
CTN: flash
key: notice
msg: Deleted mario.nes from snes.
---
CTN: system-games-list
...fresh state...
---
```

The consumer interprets act-like blocks (`flash`, `navigate`) client-side. The wire's job ends at relaying them. The single exception is §6.

### §5.4 Idempotency expectations

GET MUST NOT mutate (the usual caveat: a backing read MAY have benign internal effects, e.g. a lazy mount; the *unit contract* stays read-shaped). Mutations go via POST. The adapter does not enforce this — IN-SPEC declarations and instance discipline do — but consumers MAY cache GETs on that assumption.

## §6 Opt-in Post-Redirect-Get — the `_return` field

A plain HTML `<form>` posting to a `.ctn` URL with no script would otherwise render raw CTN in the browser. A form POST that carries a hidden field named **`_return`** opts into Post-Redirect-Get:

1. The mod runs exactly as in §5.3.
2. The adapter extracts every `CTN: flash` block from the response and stashes each `{key, msg}` into the session's flash slot (ACTS-SPEC §5.6 semantics: one-shot, injected as facts on the next request, cleared after read).
3. The adapter answers `303 See Other` with `Location: <_return>`.
4. The remaining response CTN (fresh content blocks) is discarded — the follow-up GET re-renders.

**Safety.** `_return` MUST be a same-origin relative path: it MUST begin with `/`, and MUST NOT begin with `//` (protocol-relative) or contain a `://` scheme separator. A `_return` failing this test MUST be ignored (the request proceeds as a verbatim §5.3 POST). This is the open-redirect guard.

**Reservation.** `_return` is a reserved arg name on the wire. The adapter consumes it; it MUST NOT be forwarded to the mod.

Programmatic and XHR callers simply send no `_return` and keep the verbatim contract. The branch is **opted by the consumer**, never sniffed from headers — property 1 of §1 survives.

## §7 The arg lifecycle on the wire

The wire serves direct container invocations — there is no place composition on this surface, so ACTS-SPEC §6's positions 1–3 (place per-line args, `@args` URL bindings) do not occur. The adapter MUST realize **positions 4–6 on every request**, merged in this order, later wins:

1. **Session-resolved facts** (position 4): the session store's facts, plus each drained flash entry injected under its `key` (ACTS-SPEC §5.6).
2. **Header-resolved facts** (position 4, sibling): `bearer` from `Authorization: Bearer <token>` (RFC 6750), when present.
3. **Query string** (position 5).
4. **POST body, urlencoded** (position 6).
5. **POST body, multipart** (position 6): non-file fields as string args; each uploaded file as a `__file:<field>` arg carrying the implementation's uploaded-file value.

IN-SPEC filtering applies per the implementation's IN realization: a **schema-registered** mod receives only the args its schema declares, which is what makes this merge safe — adapter-injected facts (`notice`, `flash_error`, `bearer`, `__file:*`) reach only mods that declare them. An implementation MAY additionally offer schema-less registration that passes the merged map through unfiltered; instances SHOULD schema-register every mutation endpoint and every mod that reads adapter-injected facts, keeping bare registration for arg-less display units. The adapter-consumed reservations (`_return`, §6) are removed from the map regardless of registration mode — the adapter MUST NOT rely on schema filtering to honor its own reservations.

Realm and router reservations apply on the wire as on any HTTP surface, per ACTS-SPEC §6's reserved-names rule: an implementation drops its realm's routing/diagnostic keys (e.g. `q`, `trace`) from positions 5–6 before the merge. The wire's own reserved names are `refresh` (per-line, §4.3) and `_return` (§6).

Implementations MUST cap multipart buffering (advisory default: 32 MiB) and SHOULD make the cap configurable per adapter instance.

## §8 Sessions and flash

The wire uses the same encrypted, sliding-window session cookie as the web adapter (shared adapter machinery). Per request, the adapter:

1. reads the cookie and drains the flash slot (entries become position-4 facts for this request and are cleared);
2. after the invocation, re-writes the cookie when it changed or already existed (sliding the window).

Advisory TTL default: 14 days; zero means a browser-session cookie. The `Secure` flag MUST be settable and SHOULD be on in production HTTPS deployments.

Because the wire runs no act dispatch, mods cannot write the session through `CTN: session` blocks on this surface — such blocks relay to the consumer like any other block. The only adapter-performed session writes are the sliding-window refresh, the flash drain, and the §6 flash stash. An instance needing server-side session mutation from a mutation endpoint routes that flow through the web adapter, or waits for a future revision (§12).

## §9 Representation, errors, and status

### §9.1 Content type

Every CTN response — `.spec` and `.ctn`, success and in-band error — is `text/ctn; charset=utf-8`. (Implementations whose internal raw renderer labels output `text/plain` MUST re-label on this surface.)

### §9.2 Errors travel in-band

The wire keeps the error-as-CTN-block discipline: a mod- or Core-emitted `CTN: error` block is **body, not transport** — relayed with HTTP `200`, carrying its own `code:` field for the consumer. Transport-level status is reserved for the adapter's own routing verdicts:

| Condition | Status |
|---|---|
| unknown suffix / malformed path / outside prefix | `404` |
| no resolvable place (§4.1), headless `.spec` | `404` |
| **container id unknown** — the Core's specific container-not-found error | promoted to `404` |
| place file fails to load/parse | `500` |
| `.events` | `501` |
| everything else, including in-band `CTN: error` | `200` |

The promotion rule is deliberately narrow: only the Core's own *container-not-found* block becomes a transport 404 (so a fat-fingered URL behaves like a missing resource); an upstream error that merely contains the words "not found" MUST NOT be promoted. An implementation MAY detect the Core's block by its exact message shape (the Core does not type its own errors distinctly from mod-emitted ones on the wire); a mod emitting the byte-identical phrase is then indistinguishable by construction and MAY be promoted — the MUST NOT binds only the merely-contains case. Consumers MUST therefore check bodies for `CTN: error` rather than trusting status alone.

## §10 Reserved: `.events`

`GET /{name}.events` is reserved for a server-sent-events stream of a unit's CTN over time (live blocks for polling-free consumers). Until a revision specifies it, the adapter MUST answer `501 Not Implemented` — not `404`, so consumers can distinguish "not yet" from "never".

## §11 Conformance

A **wire adapter** claim requires:

1. The §3 surface: prefix handling, the three suffixes, 404 for everything else.
2. `.spec` responses that are a single structure-only `CTN: place` block per §4, with `refresh` lifted and per-line args resolved.
3. `.ctn` GET/POST per §5, verbatim relay, no act dispatch.
4. The `_return` PRG branch per §6, including the open-redirect guard and the no-sniffing rule.
5. Arg lifecycle positions 4–6 on every request per §7, IN-filtered.
6. Session/flash behavior per §8.
7. The §9 status table, including the narrow 404-promotion rule and in-band errors at `200`.

Current implementations:

| Implementation | Wire adapter | Notes |
|---|---|---|
| containerist-go | yes (`adapters/spa`) | the reference this spec was extracted from; package predates the `wire` name |
| containerist-php | no | web + cli adapters only |
| containerist-ts | no | |

## §12 Open questions (deferred, recorded)

- **`columns:` in the place grammar.** Shipped in the Go place loader + spec renderer; not in PLACE-SPEC. Promote or quarantine — a PLACE-SPEC decision, not a wire one (§4.4).
- **Named reusable regions ("stacks").** The rook-flasher instance serves parameterized, reusable region files via an instance-level `/stacks/{name}.spec` endpoint. Promoting it would (a) need a second consumer to prove generality and (b) collide with the *retired* 4.x meaning of "stack" (PLACE-SPEC §"4.x migration"). Deliberately not in 0.1.
- **`.events`.** Contract for SSE block streams (§10).
- **Server-side session mutation on the wire.** Whether a future revision performs `CTN: session` blocks on POST responses (a narrow act-dispatch exception), or whether that stays web-adapter-only (§8).
- **Package/term alignment.** The Go package is named `spa`; this spec names the surface `wire` (the adapter serves curl and device pipelines as much as single-page apps). Renaming the Go package is a reference-impl housekeeping question.
