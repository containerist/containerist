# Federation — Specification

spec_version: 2.0
status: second major revision — additive to v1 at the authoring surface, but introduces load-bearing new concepts (fed-rebase, deferred federation, block denylist, page-status semantics) that warrant a major version bump.
date: 2026-04-24

*Scope: MVP target, extended by operational learnings from the first reference implementation. This spec reflects what was decided, shipped, and then refined during the stitchson.net ↔ konnexus.net forcing function, and the informachines.net scenario that surfaced fed-rebase. Revisions follow from operational experience, not speculation.*

*A note on numbers. Any specific numeric value in this spec (timeouts, size caps, TTLs, depth limits, cache capacities) is an **advisory starting point**, not an empirically-grounded requirement. Implementations MUST have the mechanisms this spec names (there is a timeout, there is a size limit, etc.); specific values are guidance to be tuned once operational experience accumulates. The 2.0 revision carries one empirical note: the 256 KiB size cap bit tightly on a real producer response (konnexus `notes.ctn?term=stitches` returned 252,519 bytes, within 1% of the cap). Operators sourcing from content-rich producers should expect to raise the cap; the advisory value is conservative.*

*Companion documents: `mission.md` (Principle 6), `docs/containerist.md` (pillars and operator briefing), `CTN-SPEC.md` (block format), `PLACE-SPEC.md` (container-reference syntax and the `@federation` directive at the place-frontmatter level; replaces the archived `archive/4.x-stack-format/STACK-SPEC.md` at 5.0+), `IN-SPEC.md` (input contract), `FEDERATION-in-PHP.md` (PHP reference implementation notes).*

**Revisions.**
- *1.0 (2026-04-21, draft).* Initial MVP spec. Pre-implementation.
- *2.0 (2026-04-24).* Major revision. v1 stacks continue to work unchanged at the authoring surface, but this revision introduces three load-bearing new concepts that reshape the consumer's pipeline: fed-rebase (new §13), deferred federation (new §14), and block denylist (§15.6). The page-status semantics on federation error (§9.6) is a behavior clarification. The `@federation` directive is a new STACK-SPEC surface. These are not "additive cosmetic" changes; they're architectural enough to deserve the version bump. Changes:
  - **New §13 URL resolution in federated content (fed-rebase).** Per-stack-per-producer authored policy for rewriting navigational URLs in federated content. Three policies (`off`, `to-producer`, `to-consumer`). Resource URLs always resolve to producer regardless of policy. Directive: STACK-SPEC `@federation`. Default: `to-producer`.
  - **New §14 Deferred federation via `htmx:` prefix.** Stack-body lines of the form `htmx: http://…` emit an HTMX placeholder pointing at a consumer-local fragment endpoint; the producer fetch happens on a second round-trip after initial page paint. Server-side throughout; every pipeline guarantee (allowlist, cache, rebase, denylist, attribution, error) preserved.
  - **§2 non-goals reshaped.** Replaced the ambiguous "client-side federation via htmx" non-goal with (a) the deferred form now in-scope via §14 and (b) a concrete architectural rejection of direct browser-to-producer federation (every visitor's browser would need a full JavaScript Containerist runtime; violates mission principle 2 and pillar 3).
  - **§4.5 prefix reservation** replaced by §14's concrete specification.
  - **§5 pipeline** gains a rebase step between response validation and cache store.
  - **§8.3 cache key** gains a policy component (so different rebase policies for the same URL don't share a cache entry).
  - **§8.2 cache store** clarified: implementations MAY disable the cache globally via configuration (for memory-constrained / debugging scenarios); not recommended for production traffic.
  - **§9.6 new** — federation errors do NOT mutate the consumer page's HTTP status. Local errors still do. The distinguishing field is the presence of `origin:` on the error block.
  - **§7.2 size-cap guidance updated** with the 252 KB empirical note.
  - **Renumbering.** Old §13–§16 (Security, Transport, Conformance, Evolution) are now §15–§18. No cross-references elsewhere in the spec depended on the old numbering.
  - **Appendix B** updated to reflect the actual PHP reference file layout (federation lives in `core/lib/containerist/federation.php`, integrated into `core/mods/stack-ctn.ctn.php`; config in `core/config.defaults.php`).

---

## §1 Purpose

Federation realizes Principle 6 of the Containerist mission: **"The container can be displayed on any site, in any stack. The source can come from a different site than the displayed stack's site."** It generalizes Principle 5 (REUSE) from within-site to cross-origin. Together with Imperative I (ACCESS) and Imperative II (AUTHORSHIP), federation defines how a container authored at one origin appears, unchanged in authorial intent but possibly adapted in URL-space, inside a stack rendered at another.

Federation is **transclusion over HTTP**. A stack line points at a foreign URL; the implementation fetches the CTN the URL emits and merges it into the combined CTN as if it had been produced locally. The author keeps the content; the consumer renders it.

Two modes are specified: **direct** (the consumer fetches during stack render — §4.1, §5) and **deferred** (the consumer defers the fetch to a second round-trip after initial page paint — §14). Both are server-side; the consumer is in the render path either way.

---

## §2 Scope and non-goals

### In scope for v2.0

- Stack lines may reference foreign container URLs over HTTP or HTTPS (direct federation — §4.1).
- Deferred federation via the `htmx:` stack-line prefix extended to foreign URLs (§14). Producer fetch moves to a second round-trip; pipeline guarantees are preserved.
- Consumer-side server-side fetch — the consumer mediates every federation round-trip, direct or deferred.
- Trusted-origin allowlist as the sole trust mechanism.
- Error-as-CTN-block semantics for fetch failures.
- In-memory cache with TTL and ETag.
- Depth-limited transitive federation.
- Zero-block responses as valid.
- URL resolution in federated content — authored per-stack-per-producer rebase policy for navigational URLs; resource URLs always resolve to producer (§13).

### Explicit non-goals for v2.0

- **Direct browser-to-producer federation.** Rejected architecturally, not deferred. A protocol variant where the consumer emits an HTMX placeholder pointing at the producer URL itself (no consumer hop at fetch time) would require, on the client, a full second Containerist runtime: CTN parser, Mustache engine, Markdown renderer, skin templates shipped to the browser, the rebase rewriter reimplemented in JS, the block denylist reimplemented in JS, and a JS federation client. The browser receives `text/plain` CTN from the producer; without all of the above in JavaScript, the content arrives unrendered. This violates mission principle 2 ("so wenig Code zwischen mir und meinem Inhalt wie möglich") in letter and spirit: every visitor's browser would carry a full-framework JavaScript twin. It also violates pillar 3 ("consumer renders via skin templates") by shifting the render boundary from server to client. Not in scope, not deferred, not conditional on a flag. If client-side rendering ever becomes a Containerist goal, that is a framework-level pivot, not a federation feature; federation would inherit the capability rather than pioneer it. See §14 for the deferred-but-still-server-side form which gets the TTFB advantages without any of the architectural costs.
- **Multi-tier trust levels.** All allowlisted origins are equally trusted. No partial trust.
- **Signed / hash-verified payloads.** Trust follows allowlist membership, not cryptographic integrity.
- **Authenticated federation.** All fetches are anonymous. Federation transports public content only.
- **Write-side federation.** POST/PUT/DELETE across origins is not defined. Federation is read-only in v2.
- **HTTP→HTTPS auto-upgrade.** Scheme in the allowlist entry is respected exactly.
- **Streaming / chunked rendering.** The full response is read before parsing; partial rendering is not supported.
- **Cache purge / invalidation API.** TTL expiry is the only invalidation mechanism.
- **Wildcard allowlist entries.** Exact scheme+host match only.
- **Per-ref rebase policy.** Rebase is authored per-stack-per-producer (§13). Per-ref granularity is a future addition if and when a forcing case surfaces (one stack that both re-presents and quotes from the same producer).
- **Path translation under rebase.** `to-consumer` rebase assumes producer and consumer share the same URL path structure for the rebased namespace (origin swap is the entire transformation). Cross-path translation is deferred.
- **Producer-prohibits-rebase directive.** The producer already has unilateral authority via URL form: emitting absolute URLs survives every consumer rebase policy unchanged. A protocol flag for "forbid rebase" would duplicate what the producer can already express.

Items in this list are deferred (unless explicitly "rejected architecturally"), not refused. Promotion to a future spec revision requires a forcing use case.

---

## §3 Producer contract

A container origin that participates in federation MUST serve its container at an HTTP or HTTPS URL, with the following properties:

### §3.1 Response format

- **Status.** `200 OK` is the only success status. `3xx`, `4xx`, and `5xx` are fetch failures (see §9).
- **Content-Type.** `text/plain; charset=utf-8`. Other content types are rejected by the consumer.
- **Body.** Zero or more CTN blocks (CTN-SPEC §2), separated by blank lines.
- **Empty body is valid.** Zero blocks is a valid response, not an error. The consumer contributes no content to the combined CTN for that stack line.

### §3.2 Determinism

- For identical query parameters within a short temporal window, the response SHOULD be identical. Producers are not required to guarantee bit-for-bit stability, but churn undermines consumer cache effectiveness.

### §3.3 Taxonomy privacy

- A producer MAY serve an empty response for a term that does not exist, has been renamed, is private, or simply has no members. The producer is not required to distinguish these cases to the consumer.
- This is a deliberate design property: **the producer's taxonomy is not leaked to the consumer.** Consumers observe presence-or-absence of content, not structural metadata.

### §3.4 Provenance (recommended)

- A producer SHOULD include an authoritative URL for each emitted block, typically as `metadata.url` in the block's frontmatter fields, pointing at the block's canonical location at the origin.
- Consumers render this URL as visible attribution (see §12).
- `metadata.url` is also the field through which the consumer's rebase pass (§13) populates producer-anchored attribution regardless of the navigation rebase policy — attribution and in-body navigation are separate concerns (§13.5).
- Not mechanically enforced — future revisions may formalize.

### §3.5 Caching hints (optional but recommended)

- Producers MAY emit `ETag` and/or `Last-Modified` headers on federation responses.
- Producers MAY emit `Cache-Control: max-age=<n>` to hint TTL to consumers.
- `Cache-Control: no-store` signals that the response MUST NOT be cached.
- Consumers honor these (see §8).

---

## §4 Consumer stack-line syntax

Federation refs come in two shapes: **direct** (§4.1, inline at render time) and **deferred** (§4.6, via the `htmx:` prefix, resolved after initial page paint).

### §4.1 Detection rule (direct federation)

- A stack-body line whose first non-whitespace token begins with `http://` or `https://` (after Mustache substitution per §4.4) is a **direct federation ref**.
- All other lines follow existing STACK-SPEC §7 semantics (local mod, static container reference, or prefixed reference).

### §4.2 Basic form (direct)

```
CTN: stack
---
stitchson-header
https://konnexus.net/notes.ctn?term=stitches
stitchson-footer
```

The middle line is a direct federation ref. The implementation fetches the URL synchronously during stack render, parses the response as CTN, and inlines the resulting blocks between the `stitchson-header` output and the `stitchson-footer` output.

### §4.3 Per-line args (query-string merge)

- A federation ref MAY include a query string directly in its URL.
- A federation ref MAY also receive per-line args per STACK-SPEC §7.3, merged into the URL's query string with **per-line args winning** on key collision (consistent with STACK-SPEC's later-wins precedence).
- Example:
  ```
  https://konnexus.net/notes.ctn?term=stitches&limit=5
  ```
  fetches with `term=stitches&limit=5`.

### §4.4 Mustache substitution in URLs

- A federation ref URL MAY contain Mustache-style substitutions (`{{name}}`, `{{N}}`) resolvable against the stack's `@args` frontmatter, per STACK-SPEC §5.
- Substitutions are performed **before** the federation-detection rule; the post-substitution string is what must begin with `http://` or `https://`.
- Substitution values are URL-encoded before injection into the URL.
- Example:
  ```
  @args: id = {{2}}
  ---
  https://konnexus.net/note.ctn?id={{id}}
  ```

### §4.5 URL rebase policy (authored in stack frontmatter)

- The stack frontmatter directive `@federation` (STACK-SPEC §6.4) declares, per producer origin, how navigational URLs inside federated content are resolved.
- The directive is scoped to the stack in which it appears; it applies to every federation ref (direct or deferred) against the named producers in that stack.
- Full mechanism in §13.

### §4.6 Deferred form (`htmx:` prefix)

- A stack-body line of the form `htmx: <url>` where `<url>` (after substitution) begins with `http://` or `https://` is a **deferred federation ref**.
- At stack render time, the consumer emits an HTMX placeholder pointing at a consumer-local fragment endpoint; the producer fetch happens on a second round-trip after initial page paint.
- The `htmx:` prefix was reserved in STACK-SPEC §7 for lazy loading of local mods; extending to foreign URLs is backward-compatible (no existing stack breaks) and inherits the fragment-loading mechanism already present in Containerist skins.
- Full mechanism in §14.

---

## §5 Consumer resolution pipeline

When the implementation encounters a federation ref during stack-body dispatch, it MUST execute the following steps in order. The pipeline is shared between direct and deferred refs; deferred refs defer the *execution* of steps 3–9 to a second round-trip (§14) but run the same steps.

1. **Substitute** any Mustache references in the URL against stack `@args` (§4.4).
2. **Merge** per-line args into the URL's query string (§4.3).
3. **Check allowlist** (§6). On miss: emit `CTN: error` with code 403, skip all further steps.
4. **Check depth budget** (§10). On exceed: emit `CTN: error` with code 508, skip further steps.
5. **Check cache** (§8). On hit within TTL: use cached body, skip fetch. Cache key includes the rebase policy (§8.3, §13.6).
6. **Fetch** the URL with timeout (§7), size limit (§7), and `User-Agent` header (§7.3). On failure: emit `CTN: error` with appropriate code (§9), store nothing in cache.
7. **Validate** response (status, content-type). On invalid: emit `CTN: error` (§9).
8. **Rebase** navigational and resource URLs in the response body per the stack's `@federation` policy (§13). This happens before cache store so cached content is already in final form.
9. **Store** in cache with TTL (§8).
10. **Parse** the response body as CTN (CTN-SPEC §2).
11. **Inline** the resulting blocks into the stack's combined CTN at the federation ref's position.

Failures at any step produce a `CTN: error` block in place of the ref's output. The rest of the stack still renders.

**Implementation MAY** additionally apply a block denylist between steps 7 and 8 to strip control-flow blocks (`CTN: redirect`, `CTN: session`, `CTN: flash`, `CTN: content-type`) that a federated producer could emit to hijack the consumer's act dispatch. See §15.6 and implementation-specific documentation (FEDERATION-in-PHP.md) for the reference behavior.

---

## §6 Allowlist

### §6.1 Mechanism

- Federation is opt-in per origin. A consumer MUST maintain an allowlist of scheme-qualified origins that are permitted federation targets.
- Default allowlist is empty. A fresh Containerist installation cannot federate until the operator populates it.

### §6.2 Match semantics

- An allowlist entry is `<scheme>://<host>` — no port variant (port mismatch rejects), no path, no query, no wildcard.
- Scheme MUST match exactly: `http://example.com` and `https://example.com` are distinct entries.
- Host MUST match exactly: `example.com` and `www.example.com` are distinct entries.
- Port MUST match if present in the URL; default ports (80 for HTTP, 443 for HTTPS) are implicit.

### §6.3 Allowlist trumps IP blocklist

- A consumer SHOULD implement an SSRF defense that blocks fetches resolving to private IP ranges (loopback, RFC 1918, link-local) by default.
- **An allowlisted origin bypasses this blocklist.** Rationale: the allowlist is an explicit declaration of trust. Operators federating between co-located services (same-host Apache vhosts, in-cluster services) MUST be able to do so; the SSRF defense protects against *unknown* URLs probing internal services, not *known* ones.
- Implementations MUST document this tradeoff in their configuration docs.

### §6.4 Configuration format (implementation-defined)

- The allowlist lives in the consumer's configuration (environment variable, config file, or equivalent). The exact format is implementation-specific; containerist-php, containerist-ts, and containerist-go may differ.
- The conformance suite verifies semantics, not storage format.

### §6.5 Rejection

- A federation ref to a non-allowlisted URL emits:
  ```
  CTN: error
  code: 403
  origin: <url>
  message: origin not in federation allowlist
  ---
  ```

---

## §7 Fetch limits

### §7.1 Timeout

- Implementations MUST enforce a request timeout (total: connect + read).
- Advisory starting point: **~3 seconds**. Tune based on observed producer latency and consumer tolerance for request-path delay.
- Implementations SHOULD make this configurable globally and per-origin.
- On timeout: `CTN: error` code 504 (§9).

### §7.2 Response size

- Implementations MUST enforce a maximum response body size.
- Advisory starting point: **~256 KiB** for text-CTN payloads. **Empirical note (2026-04-24):** a real producer response (`konnexus.net/notes.ctn?term=stitches`) clocked in at 252,519 bytes — within 1% of this cap. Operators sourcing from content-rich producers should expect to raise the cap. The advisory value is conservative for small producer sites; it is tight for any site that serves multi-block aggregates.
- Implementations SHOULD make this configurable per-origin as well as globally.
- On overflow: `CTN: error` code 413 (§9). The partial response is discarded.

### §7.3 User-Agent header

- Every federation fetch MUST send a `User-Agent` header identifying itself as a Containerist federation client.
- Recommended format: `Containerist-Federation/<spec-version> (+<consumer-origin>)`.
- Example: `Containerist-Federation/2.0 (+https://stitchson.net)`
- Producers MAY use this for logging, rate-limiting, or differential responses.

### §7.4 Other headers

- `Accept: text/plain; charset=utf-8` MUST be sent.
- `Accept-Encoding: gzip` MAY be sent; implementations handle decompression transparently.
- No `Authorization`, `Cookie`, or other credential headers in v2.

---

## §8 Cache

### §8.1 Purpose

- Cache reduces network round-trips and relieves producers of redundant work. Federation without caching is unacceptably slow under any traffic.

### §8.2 Store

- v2: in-memory, per-process LRU. Not shared across processes, not persisted.
- Implementations MAY introduce shared-store backends later; v2 does not require it.
- Implementations MAY expose a global kill-switch (configuration flag) to disable caching entirely. This is a dev / debugging / memory-constrained affordance, **not** a production feature — spec §8.1's performance rationale still applies. The PHP reference exposes `FEDERATION_CACHE_ENABLED`; default `true`.

### §8.3 Key

- Cache key is the canonicalized fetch URL (scheme + host + port + path + query string, with query parameters sorted alphabetically by key) **plus** the active rebase policy for the fetch (§13).
- Canonical form: `<url>|<policy>` where `<url>` is the canonicalized URL string and `<policy>` is one of `off`, `to-producer`, `to-consumer`.
- The policy component prevents a cache entry stored under one policy from being served under a different policy (the bodies would differ because rebase is applied before cache store per §5 step 8).
- Per-line args and Mustache substitutions are resolved BEFORE key computation.

### §8.4 TTL

- Implementations MUST apply a TTL to cached entries. Cached-forever is not acceptable.
- Advisory starting point: **~60 seconds** for editor-edited content that may change during a working session. Longer for stable content; shorter for high-churn sources.
- Producer's `Cache-Control: max-age=<n>` header, if present and ≥ 0, overrides the implementation default.
- `Cache-Control: no-store` from the producer disables caching for that entry.
- Implementations MAY cap the maximum honored TTL.

### §8.5 ETag support

- On fetch miss where a prior cached entry's TTL has expired but an ETag is stored, the consumer SHOULD send `If-None-Match: <etag>`.
- On `304 Not Modified`: reuse the cached body, refresh the TTL.
- On `200 OK`: replace the cached entry.

### §8.6 Capacity

- Implementations MUST bound the cache size to prevent unbounded memory growth.
- Advisory starting point: a few hundred entries per process for small consumer sites. Scale up for bigger traffic; scale down on memory-constrained hosts.
- Configurable per implementation.

### §8.7 No purge API

- v2 does not define a cache-invalidation mechanism beyond TTL expiry. Operators restart the process or wait out the TTL.
- Deferred for future revision; see §2 non-goals.

---

## §9 Error semantics

### §9.1 Errors become CTN blocks

- All fetch failures emit a `CTN: error` block in place of the federation ref's output. They do NOT abort the stack render; other stack lines still produce their content.
- This is consistent with the existing control-flow pattern (see `briefing-detailed.md` on control-flow CTN blocks).

### §9.2 Error block shape

```
CTN: error
code: <numeric>
origin: <url>
message: <short description>
---
<optional body with more detail, renderable by skin>
```

The `origin:` field is the load-bearing distinguisher between federation errors (which carry an origin) and local errors (which don't). §9.6 depends on this.

### §9.3 Defined codes

| Code | Condition |
|------|-----------|
| 403  | Origin not in allowlist (§6) |
| 413  | Response body exceeded size limit (§7.2) |
| 415  | Unexpected `Content-Type` (§3.1) |
| 502  | Fetch failed (connection refused, DNS failure, 5xx from origin, malformed response) |
| 504  | Request timeout (§7.1) |
| 508  | Federation depth limit exceeded (§10) |

### §9.4 HTTP status propagation

- Producer-side 4xx / 5xx status codes are collapsed into consumer-side `code: 502` with the upstream status in the error body. Consumers do not re-propagate arbitrary upstream statuses.
- Producer-side `404` for a federation endpoint is a 502 at the consumer: the endpoint-not-found is a configuration failure, not a content-not-found (empty-body is the content-not-found signal per §3.3).

### §9.5 Skin rendering

- `skin/error.html` + `skin/error.css` MUST exist in any Containerist implementation that supports federation. It renders the error block with visible provenance (the `origin` field).
- The skin template MUST include a conditional rendering of the `origin` field (e.g., a Mustache `{{#origin}}…{{/origin}}` block) so federated errors display their attribution link.
- Errors render, they don't hide. A broken federation is visible to the end user, not silently dropped.

### §9.6 Page-level HTTP status on federation error

- A `CTN: error` block emitted by the federation client (distinguished by the presence of the `origin:` field, §9.2) MUST NOT mutate the consumer page's HTTP status. The error renders inline; the page itself stays at whatever status the non-federated portion dictates (typically `200`).
- Rationale: a federation error describes a failure to transclude one foreign block. The rest of the stack rendered fine; the page itself is a 200. Returning 502 on the whole page because one ref upstream 404'd would mislead caches, monitors, and browser UX.
- Local errors (`CTN: error` blocks emitted by local mods, distinguished by the **absence** of the `origin:` field) continue to set the page status per the block's `code`. Schema 400s, control-flow 500s, framework 404s — unchanged.

---

## §10 Depth limit

### §10.1 Transitive federation

- A federated container MAY itself federate: producer site P serves a CTN containing a federation ref to site Q. A consumer fetching from P would, on parsing, encounter Q's ref and recurse.
- Implementations MAY support this. v2 does not mandate support but does mandate a limit if supported.

### §10.2 Depth budget

- Implementations MUST enforce a depth limit to prevent transitive amplification and accidental cycles.
- Advisory starting point: depth **2**. Counted as: stack (depth 0) → federated fetch (depth 1) → federated-of-federated (depth 2). Exceeding emits `CTN: error` code 508.
- The right number is operational — revisit once real federation chains exist in the wild.
- Implementations MAY make this configurable.

### §10.3 Cycle detection

- Implementations SHOULD track the set of URLs already fetched in the current request's federation chain. A repeat fetch of the same URL within one request emits `CTN: error` code 508 even if under the depth budget.

### §10.4 Trust

- Depth-limit enforcement happens at the outermost consumer. Intermediate producers cannot extend or bypass the limit.

---

## §11 Zero-block responses

### §11.1 Not an error

- A `200 OK` response with an empty body or a body that parses to zero CTN blocks is a valid federation response.
- The consumer contributes zero blocks to the combined CTN from that stack line.
- No `CTN: error` is emitted; the stack renders as if the federation ref had produced nothing.

### §11.2 Rationale

- Supports Principle 6's taxonomy-privacy intent (§3.3): producers do not have to distinguish "no matching content" from "term unknown." Consumers see one signal.
- Supports composability: a federation ref is safe to include speculatively; if the producer has no matching content, the stack degrades gracefully.

### §11.3 Consumer UX

- Consumer stack designers SHOULD consider what an empty federated response looks like in context (e.g., "No fragments yet" vs. a blank region). Stack-level layout may include a locally-emitted empty-state container alongside a federation ref.

---

## §12 Provenance

### §12.1 Convention, not enforcement

- A federated block's provenance (the origin URL) is carried in the block's own `metadata.url` field by producer convention (§3.4).
- Consumer skins MUST render this URL as visible attribution when present.
- If the producer omits it, the consumer has no automatic way to attribute the block. v2 does not compensate.

### §12.2 Attribution rendering (consumer skin)

- Skins rendering federated block types (e.g. `skin/note.html` if `note` blocks are expected from federation) SHOULD display `{{metadata.url}}` as a visible link, labeled appropriately (e.g., "Source", "Read on konnexus.net").
- Attribution MUST be in-DOM, not tooltip-only, for accessibility.

### §12.3 Relationship to rebase

- Provenance (attribution) and rebase (URL resolution in body text) are distinct concerns. Attribution is about *who authored the content*; rebase is about *where in-body clicks go*. See §13.5 for the complete treatment.
- Under every rebase policy except `off`, `metadata.url` is resolved to an absolute producer URL so the attribution link always reaches the author regardless of body-navigation policy.

### §12.4 Future: framework-level attribution

- Future spec revisions may introduce a framework-enforced attribution wrapper (e.g., the implementation injects a `data-origin` attribute on every federated block's root element regardless of producer cooperation).
- Deferred from v2 to keep the consumer-parser boundary minimal.

---

## §13 URL resolution in federated content (fed-rebase)

### §13.1 Problem

Federated content frequently contains URLs that are relative to the producer's origin — Markdown link targets `[note](/n/K-id)`, HTML attributes `href="/…"` / `src="/…"`, frontmatter URL fields. When the consumer renders such content, the visitor's browser resolves those URLs against the *consumer's* origin, not the producer's. Three failure modes follow:

- **Broken link.** The consumer has no such path. Click → 404 on the consumer.
- **Wrong-destination link.** The consumer happens to have a colliding path. Click → consumer's unrelated page.
- **Wrong-brand navigation.** The link silently takes the visitor away from the consumer's presentation back to the producer, which may or may not be what the authoring operator intended.

Fed-rebase lets the consumer author a deliberate policy for URL resolution, per producer, per stack.

### §13.2 Terminology

- **Federation-domain-rebase** *(full form; verb: to rebase; short: fed-rebase)*. The consumer-side transformation of navigational URLs inside federated content from the producer's URL space to another URL space.
- **URL policy**. The authored choice of rebase target for a given producer in a given stack. Three values, §13.3.
- **Rebase target origin**. The origin against which root-relative navigational URLs in the federated content are resolved to absolute URLs.
- **Navigation URL** vs **resource URL**. A URL the browser *navigates to* on click or submit (href on `<a>`/`<area>`, action on `<form>`) vs a URL the browser *fetches as a resource* (src on img/script/iframe/audio/video/source/embed, href on `<link>`, data on `<object>`, poster on `<video>`). The distinction is load-bearing: rebase applies to navigation only; resource URLs always resolve to the producer regardless of policy (§13.4).

### §13.3 Three policies

A consumer processing a federated response MUST apply one of three URL-resolution policies to the response content's navigational URLs. Resource URLs are handled by a separate, non-authored rule (§13.4.b).

- **`off`** — No URL rewriting of any kind. Navigation and resource URLs reach the browser verbatim. The author accepts full responsibility for URL form. Intended for the rare case where the operator explicitly wants no transformation and is aware that resource URLs will also not be rewritten.
- **`to-producer`** — Root-relative navigational URLs are rewritten to absolute URLs against the producer origin. The federated block behaves as a window back to the producer: clicking a link inside the block navigates away from the consumer to the producer's canonical view. **This is the default** when `@federation` does not specify otherwise (§13.7). Safe for content the consumer does not author or host.
- **`to-consumer`** — Root-relative navigational URLs are left relative (equivalently: rewritten against the consumer origin, which produces the same on-wire result in the parallel-path case). The federated block behaves as content the consumer is re-presenting under its own namespace: clicking a link stays on the consumer site. Intended for same-author federation where the consumer re-hosts a slice of the producer's namespace (e.g., informachines.net re-presenting konnexus.net's `informachines`-tagged notes).

Under all three policies except `off`, **resource URLs** always resolve to the producer regardless of policy. Rationale: the consumer's pipeline only transports CTN text (§3.1 requires `text/plain`); binary assets — images, scripts, stylesheets, video, audio — never enter the consumer's serve path. Rebasing a resource URL to the consumer would produce a URL pointing at a file the consumer cannot serve. `off` is the one exception because the author of that policy has explicitly opted out of all transformation.

### §13.4 What gets rewritten

#### §13.4.a Navigational URLs (rebase per policy)

Under `to-producer` the consumer MUST rewrite each of the following; under `to-consumer` and `off` the consumer MUST leave them unchanged.

1. **Markdown link targets** — pattern `\[[^\]]*\]\(/(?!/)[^)]*\)` (the leading `[` and closing `](` frame an `<a>`-style link in Parsedown-compatible Markdown). Leading character NOT `!` (which would make it an image — a resource, §13.4.b).
2. **HTML `<a href="/...">`** — the `href` attribute on `<a>` and `<area>` elements whose value begins with `/` and not `//`.
3. **HTML `<form action="/...">`** / **`<button formaction="/...">`** / **`<input formaction="/...">`** — form-submission targets.
4. **Named frontmatter URL fields used as attribution.** Fields whose name is `url`, `href`, or `canonical` at any depth in the block's frontmatter map, and whose string value begins with `/` and not `//`. `metadata.url` is the canonical example. These fields are **always** rewritten to producer under every policy except `off`, because attribution must always resolve to the author regardless of where in-body navigation is routed (§13.5). Listed here because the form matches the other §13.4.a patterns.

#### §13.4.b Resource URLs (always to producer, except `off`)

Under every policy except `off`, the consumer MUST rewrite each of the following to an absolute URL against the producer origin. Under `off`, they are left unchanged.

1. **Markdown image targets** — pattern `!\[[^\]]*\]\(/(?!/)[^)]*\)`. The leading `!` distinguishes from the navigational form.
2. **HTML resource attributes** whose value begins with `/` and not `//`:
   - `src` on `<img>`, `<script>`, `<iframe>`, `<audio>`, `<video>`, `<source>`, `<embed>`, `<track>`
   - `poster` on `<video>`
   - `data` on `<object>`
   - `href` on `<link>` (stylesheets, icons, preload, etc.) — this is the one `href` that is resource-loading rather than navigational; recognized by the element being `<link>`, not `<a>`/`<area>`
   - `srcset` on `<img>` and `<source>` — each comma-separated URL in the value is rewritten independently

The consumer MUST NOT rewrite resource attributes based on attribute name alone; element context matters (`href` on `<a>` vs `<link>`).

### §13.5 What is never rewritten (under any policy)

- Absolute URLs (`http://…`, `https://…`) — already resolved.
- Protocol-relative URLs (`//host/…`) — already pin a host.
- Non-http(s) scheme URLs (`mailto:`, `tel:`, `data:`, `javascript:`, `cid:`, etc.) — not origin-relative.
- Fragment-only URLs (`#anchor`) — resolve against the containing document. Known limitation: anchors authored against the producer's page structure may not exist on the consumer's page under any policy. Authors aware of federation SHOULD avoid anchor-only links in federatable content, or use explicit absolute-with-fragment forms where the target is known.
- Document-relative URLs (`foo`, `./foo`, `../foo`) — out of scope for v2. Root-relative (`/foo`) is the common case and the only one v2 rewrites.
- CSS `url(…)` inside style attributes or `<style>` blocks — out of scope for v2. Rare in federated content; revisit if it surfaces.

### §13.6 Interaction with cache

Rebase is applied before cache store (§5 step 8). The cached body is the post-rebase form, so subsequent hits don't re-rewrite. The cache key includes the rebase policy (§8.3) so the same URL fetched under different policies in different stacks cannot collide.

Alternative considered: cache the pre-rebase body and rewrite at read. Rejected — the rewrite cost runs per-hit instead of per-miss, for no saved cache bytes at small scale. Revisit if responses get large and policies churn.

### §13.7 Authoring (`@federation` directive)

Rebase policy is authored per-stack-per-producer in the stack's frontmatter via the `@federation` directive (STACK-SPEC §6.4). Grammar:

```
CTN: stack
@federation:
  http://konnexus.net: to-consumer
  https://someone-else.example: to-producer
---
```

- Key form: `<scheme>://<host>[:<port>]` — same form as allowlist entry. Exact-match semantics; default ports implicit.
- Value form: one of the string literals `off`, `to-producer`, `to-consumer`.
- Directive absent: every federation ref in the stack uses `to-producer`.
- Directive present, producer mentioned: mentioned policy wins.
- Directive present, producer unmentioned: unmentioned producer uses `to-producer`.
- Unknown policy value: stack fails to load (`CTN: error code: 500`). Silent coercion is prohibited.

### §13.8 Recursive federation

Stack A federates producer P with policy `to-consumer`; the response from P contains a navigational link to `/page2`. Under `to-consumer`, the visitor clicks, navigates to `consumer/page2` — which renders its own stack, which federates `P/page2.ctn` — which gets its own rebase policy from its own stack.

Policy consistency across stacks is not automatic; it is achieved by the operator declaring `@federation` consistently in every stack that federates P. The stacks themselves do not communicate; each one declares its own intent.

### §13.9 Rejected alternatives (for the spec's memory)

- **Per-ref policy.** Granularity too fine for the author-intent shape; a stack has one purpose. Forcing case (one stack that both re-presents and quotes the same producer) does not exist yet. §2 non-goals.
- **Path translation under `to-consumer`.** `to-consumer` requires parallel path structures (producer and consumer share path namespace for the rebased content). Cross-path translation is a future revision.
- **Producer-declared rebase hints.** A producer emitting `origin_base: http://x` to override consumer rebase is out of scope. The consumer derives the rebase target origin from the fetch URL; producer hints are unnecessary.
- **Producer-prohibits-rebase directive.** The producer already has unilateral authority via URL form — emitting absolute URLs survives every policy unchanged. A protocol flag would duplicate what the producer can already express.
- **Direct browser-to-producer federation.** Rejected architecturally — see §2.

---

## §14 Deferred federation via `htmx:` prefix

### §14.1 Purpose

Direct federation (§4.1, §5) blocks initial page paint on every producer round-trip. A stack with three federation refs to a moderately-distant producer pays ~3× single-fetch latency as TTFB. Observation from the PHP reference implementation (`/federation-demo`, 2026-04-24): remote HTTP fetches are ~10× slower than in-process mod invocation; a page with multiple refs is visibly slower than a page without.

Deferred federation trades "federated content present in initial HTML" for "fast TTFB + federated content painted after shell-render via a second round-trip." The consumer remains in the render path — all rebase, denylist, cache, attribution, and error semantics are preserved. The only thing that changes is *when* the producer fetch happens, not *whether* the consumer mediates it.

### §14.2 Grammar

The stack-body prefix `htmx:` (STACK-SPEC §7) extends to foreign URLs:

```
CTN: stack
---
page-header
htmx: http://konnexus.net/notes.ctn?term=stitches
page-footer
```

- Detection: a stack-body line whose prefix is `htmx:` and whose name (post-substitution, post-§4.4-mustache-expansion) begins with `http://` or `https://` is a **deferred federation ref**.
- All of §4 (Mustache substitution, per-line args, query-string merge) applies identically.
- A `htmx:` line whose name does NOT begin with `http://` or `https://` is the existing local-mod-lazy-load form (STACK-SPEC §7); backward-compatible.

### §14.3 Pipeline

Deferred federation runs in four phases:

1. **Stack render (initial request).** The consumer's implementation encounters the `htmx: <url>` line during stack-body dispatch. It does NOT fetch from the producer. It emits a `CTN: htmx-placeholder` block whose `url` field points at a **consumer-local federation fragment endpoint** carrying the producer URL as a parameter. The placeholder renders via `skin/htmx-placeholder.html` — typically a `<div hx-get="..." hx-trigger="load">` with a skeleton/loading state.
2. **Initial response delivered.** The consumer emits the assembled HTML (shell + placeholders). TTFB is local-render-only. Browser paints the page; placeholders are visible as loading states.
3. **Fragment request (deferred round-trip).** Browser's HTMX runtime fires a GET to the placeholder's URL. The consumer's adapter receives this request, recognizes it as a federation-fragment request, runs the full federation pipeline (§5 steps 3–11) on the embedded producer URL.
4. **Fragment returned, swap.** The consumer returns HTML (already skinned by its own skin templates). HTMX swaps it into the placeholder. Page now contains the federated content, rendered under the consumer's skin.

### §14.4 Consumer fragment endpoint

The consumer MUST provide a local URL pattern that accepts a federation ref and returns an HTML fragment. Exact path is implementation-defined; the PHP reference uses (by convention) a URL pattern with the `.htmx` suffix (e.g., `/fed.htmx?ref=<urlencoded-producer-url>`). The endpoint:

- MUST validate the ref URL against `FEDERATION_ALLOWLIST` before fetching. A non-allowlisted URL at this endpoint emits the same `CTN: error code: 403` as direct federation; the error renders as the fragment (visible loading state → visible error).
- MUST run the full resolution pipeline (§5) on the embedded producer URL — rebase, denylist, cache, attribution, error — with no deviations.
- MAY accept additional query parameters intended as per-line args to merge into the producer URL's query string (same semantics as §4.3).
- MUST emit the response as `text/html` (the fragment), not `text/plain` (the CTN). Unlike the `.ctn` suffix, this endpoint returns *rendered* output because HTMX swaps HTML.
- MUST reject requests where the ref URL is missing, malformed, or not http(s).

### §14.5 Same pipeline, same guarantees

Every guarantee from direct federation applies unchanged:

| Concern | Behavior |
| --- | --- |
| Allowlist (§6) | Same. Enforced at the fragment endpoint. |
| Cache (§8) | Same. Shared across visitors — the second visitor hitting the same placeholder URL gets the cache. Cache key (§8.3) uses the producer URL + policy, not the fragment-endpoint URL, so direct and deferred forms for the same URL share the cache. |
| Fed-rebase (§13) | Same. Applied server-side inside the fragment endpoint before the fragment is returned. The fragment arrives at the browser already rebased. |
| Block denylist (implementation-MAY, see §5) | Same. Applied before cache store, same as direct. |
| Attribution (§12) | Same. Skin templates applied server-side; attribution link rendered as part of the fragment. |
| Error semantics (§9) | Same. `CTN: error` blocks render via `skin/error.html`; page HTTP status stays 200 (the page-level request already returned 200; the fragment request is independent and its own status applies to its own round-trip). |
| Size / timeout / depth limits (§7, §10) | Same. Enforced at the fragment endpoint. |

### §14.6 What's different

- **TTFB.** Initial page paint does not block on producer latency. A stack with N deferred federation refs has TTFB equal to local-render-only.
- **Perceived load shape.** Shell arrives fast; federated regions show loading states; content fills in after the second round-trip. Visual "loading" skeleton is the author's/skin-designer's choice.
- **JS dependency.** HTMX is required for the deferred form to actually load content. A client without JS sees the placeholder permanently. HTMX is already the Containerist-standard lazy-load mechanism (STACK-SPEC §7) — not a new dependency, but this form makes it more central for federation-heavy pages.
- **Crawler visibility.** Simple crawlers that don't run JS see only placeholders. Federated content under `htmx:` is effectively invisible to such crawlers. If the federated block must be crawlable, use the direct form.

### §14.7 Mixing direct and deferred in one stack

Authors MAY mix direct and deferred federation refs in the same stack:

```
CTN: stack
@federation:
  http://konnexus.net: to-consumer
---
federation-intro
http://konnexus.net/today-one-year-ago.ctn         # direct — needed in initial HTML
htmx: http://konnexus.net/notes.ctn?term=stitches  # deferred — large; defer to post-paint
page-footer
```

- `@federation` policy applies uniformly to both forms against the same producer.
- Direct refs block TTFB; use for small, fast, or SEO-critical content.
- Deferred refs don't block TTFB; use for large, slow, or below-the-fold content.
- Per-ref choice.

### §14.8 SEO guidance (non-normative)

Search engines vary in JS execution capability. Under direct federation, federated content is in the initial HTML and is crawled normally. Under deferred federation, only the placeholder is in the initial HTML; the content arrives via XHR. Authors for whom federated content must be indexable SHOULD use direct federation for that content; authors for whom TTFB dominates MAY use deferred federation.

Future spec revision may add a `<noscript>` fallback convention (the deferred-federation placeholder includes a `<noscript>` block with the direct form inline) to give authors both properties. Out of scope for v2.0.

---

## §15 Security model

### §15.1 Trust boundary

- Allowlist membership is the entire trust decision. Content from an allowlisted origin is treated identically to locally-produced content.
- Skin rendering (Mustache templates per `docs/containerist.md` pillar 3) does not sandbox federated blocks. Unescaped body rendering (`{{{body}}}`) applies equally to federated and local blocks.
- **Do not allowlist origins you would not trust to write into your own repository.** This is the operational rule.

### §15.2 SSRF defense

- Implementations SHOULD block fetches that resolve to private / loopback / link-local / multicast IP ranges by default.
- Allowlisted origins bypass this defense (§6.3). Rationale stated there.

### §15.3 No credential propagation

- No `Cookie`, `Authorization`, `Set-Cookie` replay, or session-token forwarding across federation boundaries.
- Federated content is public by construction.

### §15.4 Response content-type enforcement

- Only `text/plain; charset=utf-8` responses are accepted (§3.1). Other content types emit `CTN: error` code 415.
- Rationale: reject misconfigured producers emitting HTML (which might execute as skinned-through content) or JSON (wrong format).

### §15.5 DoS considerations

- Size limits (§7.2) bound memory exposure.
- Timeouts (§7.1) bound request latency.
- Cache (§8) bounds repeat-fetch cost.
- Depth limits (§10) bound transitive amplification.
- v2 does not defend against producer-controlled slowloris variants beyond the request timeout.

### §15.6 Block denylist (implementation-MAY)

- Implementations MAY strip control-flow blocks (`CTN: redirect`, `CTN: session`, `CTN: flash`, `CTN: content-type`) from federated responses before inlining. Rationale: a federated producer emitting `CTN: redirect` would otherwise hijack the consumer's navigation; `CTN: session` would write to the consumer's session store; `CTN: flash` would write to consumer flash; `CTN: content-type` would change response handling.
- The denylist is stricter than the allowlist posture (§15.1) — it assumes a trusted-but-not-fully-aligned producer, a reasonable posture for same-operator federation where policy is set once and then safely enforced thereafter.
- The PHP reference enables this by default via `FEDERATION_BLOCK_DENYLIST`. Operators who want to disable it set the constant to an empty array; doing so is equivalent to the §15.1 default "allowlist is the entire trust decision."
- Implementations that strip blocks SHOULD do so before cache store (so cached content is pre-stripped).

---

## §16 Transport

### §16.1 HTTPS preferred, HTTP permitted

- HTTPS is strongly preferred. Allowlist entries SHOULD use `https://`.
- `http://` entries ARE permitted in v2 to support pre-TLS producers (real-world case: a producer not yet configured with a certificate).
- Consumers MUST NOT auto-upgrade `http://` to `https://` implicitly. If the operator wants HTTPS, the allowlist entry is edited explicitly.

### §16.2 MITM posture

- Consumers MUST document the MITM risk of HTTP federation in their configuration docs.
- Operators using HTTP federation accept: (a) network attackers can alter the response content en route, (b) response metadata (URLs, query strings) is visible in transit.
- Federation over HTTP is acceptable for public content with low content-integrity stakes, where the operator controls both endpoints or trusts the network path.

### §16.3 Future: HTTPS-only enforcement flag

- A future spec revision may introduce a config flag forbidding HTTP entries. Deferred; see §2 non-goals.

---

## §17 Conformance

### §17.1 Test fixtures

- Test fixtures live in `conformance/federation/`.
- Each fixture defines: an input stack file, a simulated producer response (or sequence of responses), and the expected combined CTN output.
- Fixtures cover, at minimum:
  - Allowlist hit (happy path)
  - Allowlist miss → code 403
  - Empty-body response → zero blocks inlined
  - Malformed CTN response → code 502
  - Timeout → code 504
  - Size overflow → code 413
  - Wrong Content-Type → code 415
  - Cache hit (second call within TTL returns cached body)
  - Cache miss after TTL (refetches)
  - ETag round-trip (304 reuses cache)
  - Depth exceeded → code 508
  - Cycle detected → code 508
  - Mustache URL substitution resolves before federation detection
  - Per-line args merge into URL query string with later-wins precedence
  - Fed-rebase `to-producer` on navigational URLs (Markdown + HTML forms)
  - Fed-rebase `to-consumer` leaves navigational URLs relative
  - Fed-rebase `off` leaves all URLs untouched
  - Resource URLs always resolve to producer under `to-producer` and `to-consumer`; leave relative under `off`
  - Unknown `@federation` policy value → stack fails to load
  - Deferred federation round-trip: initial request emits placeholder; second request to fragment endpoint returns skinned HTML
  - Cache-key unification: same URL fetched via direct and deferred forms shares a single cache entry
  - Federation error does NOT mutate page HTTP status (§9.6)
  - Local error DOES mutate page HTTP status
  - Block denylist strips `CTN: redirect` / `session` / `flash` / `content-type` from federated response (implementation-MAY)

### §17.2 Reference implementations

- Reference consumer: `containerist-php` as of 1.1 drafting. Configurations: direct federation, deferred federation via `/fed.htmx?ref=…`, three-policy rebase, block denylist, cache with ETag.
- Reference producer (initial): `konnexus.net` serving `notes.ctn`, `term-intro.ctn`, `today-one-year-ago.ctn`, `all-notes.ctn`. Temporary; to be replaced with a deterministic fixture server in `conformance/federation/server/` before spec finalization.
- `containerist-ts` and `containerist-go` to follow.

### §17.3 Conformance posture

- An implementation claims federation conformance when all fixture tests pass.
- Partial conformance (e.g., no depth-limit support, no deferred form) is permitted but MUST be documented in the implementation's README.

---

## §18 Evolution

### §18.1 Revision basis

- v1.0 reflected what was designed for the Stitchson forcing function pre-implementation.
- v2.0 reflects what was learned during the first PHP reference implementation (fed-rebase, deferred federation, block denylist, page-status semantics on federation error) plus the informachines re-presentation scenario that surfaced fed-rebase.
- Subsequent revisions follow from **operational experience**, not speculative design.
- Deferred items in §2 are candidates for future revisions; each requires a real use case to promote.

### §18.2 Known deferred items (watchlist)

- Per-ref rebase policy (forcing case: one stack that both re-presents and quotes from the same producer).
- Path translation under `to-consumer` (forcing case: consumer and producer with divergent URL structures).
- Wildcard or pattern-based allowlist entries.
- Wildcard `@federation` entries (e.g., `'*': to-consumer`).
- Tiered trust (e.g., "read-only-metadata" vs "full content").
- Signed / hash-verified payloads.
- Authenticated federation.
- Write-side federation (POST/PUT/DELETE).
- Cache purge / invalidation API.
- HTTPS-only enforcement flag.
- Streaming rendering.
- Shared cache store (cross-process).
- Framework-level attribution wrapper (§12.4).
- Per-stack `@cache` directive (for authored TTL / freshness control).
- `<noscript>` fallback convention for deferred federation (§14.8).
- CSS `url(…)` rewriting under rebase.
- Document-relative URL rewriting.

### §18.3 What does NOT evolve

- The trust model (allowlist-or-nothing). Complexity-adding trust models require a named forcing use case.
- The error-as-CTN-block pattern. Alternative error signalling (HTTP status propagation, exceptions) is rejected.
- The zero-block-is-valid rule. Producer taxonomy privacy is load-bearing.
- The navigation-vs-resource distinction under rebase. Resource URLs resolving to producer is load-bearing — the consumer does not serve binary assets (§3.1 text/plain constraint).
- Server-side rendering. Direct browser-to-producer federation is architecturally rejected (§2, §15.1).

---

## Appendix A — Example flow (Stitchson + informachines)

End-to-end walkthroughs of the 2.0 federation path. Two scenarios: Stitchson consuming konnexus (the original forcing function) and informachines re-presenting a konnexus namespace slice (the scenario that surfaced fed-rebase).

### A.1 Stitchson: direct federation with default rebase

- Producer: `http://konnexus.net` serving `notes.ctn`.
- Consumer: `stitchson.net`, allowlist `["http://konnexus.net"]`, no `@federation` directive (default `to-producer`).
- Stack `stacks/index.txt`:
  ```
  CTN: stack
  ---
  stitchson-header
  stitchson-intro
  http://konnexus.net/notes.ctn?term=stitches
  stitchson-footer
  ```
- Request flow:
  1. Browser requests `https://stitchson.net/`.
  2. The renderer dispatches stack lines; federation ref detected.
  3. Allowlist passes, cache miss, GET with 3s timeout / 256 KiB cap.
  4. Response: `200 OK`, text/plain, ~N `CTN: note` blocks.
  5. Block denylist strips any `redirect`/`session`/`flash`/`content-type` blocks (none expected from `notes.ctn`).
  6. Rebase pass: `to-producer` default. Every `[text](/n/K-id)` and `<a href="/n/...">` inside note bodies rewrites to `http://konnexus.net/n/K-id`. Every `<img src="/img/...">` same. `metadata.url` already absolute or rewritten absolute.
  7. Cache store with key `http://konnexus.net/notes.ctn?term=stitches|to-producer`, TTL 60s.
  8. CTN parse, inline at ref position.
  9. Skin render: each block via its skin pair. `skin/note.html` renders title + body + `{{metadata.url}}` as "Read on konnexus.net" link.
  10. Browser receives page over HTTPS (stitchson has its own TLS).
- Result: visitor sees the notes inlined; every link in a note's body or attribution routes to konnexus.

### A.2 Informachines: re-presentation via `to-consumer`

- Producer: `http://konnexus.net` serving `informachines.ctn` (overview) and per-note `n/K-id.ctn`.
- Consumer: `informachines.net`, allowlist `["http://konnexus.net"]`, re-presenting konnexus's `informachines`-tagged content under its own brand.
- Stacks:
  ```
  # stacks/index.txt
  CTN: stack
  @federation:
    http://konnexus.net: to-consumer
  ---
  page-header
  http://konnexus.net/informachines.ctn
  page-footer

  # stacks/n--*.txt
  CTN: stack
  @args: id = {{2}}
  @federation:
    http://konnexus.net: to-consumer
  ---
  page-header
  http://konnexus.net/n/{{id}}.ctn
  page-footer
  ```
- When informachines.net renders `/` — the overview list — links inside like `[note title](/n/K-id)` are NOT rewritten (policy `to-consumer`). They stay relative.
- Browser resolves `/n/K-id` against informachines origin → `informachines.net/n/K-id` → which matches `stacks/n--*.txt` → which federates `konnexus.net/n/K-id.ctn` → also under `to-consumer`.
- Result: informachines presents konnexus's content under its own URL namespace; navigation between fragments stays on informachines. Clicking an image in a note still reaches konnexus (resources always `to-producer`, §13.4.b). The attribution link in `{{metadata.url}}` also reaches konnexus (§13.5).

### A.3 Deferred federation for TTFB

- Consumer stack:
  ```
  CTN: stack
  @federation:
    http://konnexus.net: to-producer
  ---
  page-header
  htmx: http://konnexus.net/notes.ctn?term=stitches
  page-footer
  ```
- Request flow:
  1. Browser requests `/`.
  2. The consumer emits `CTN: htmx-placeholder url: /fed.htmx?ref=<urlencoded-producer-url>`.
  3. Page shell (header + placeholder + footer) delivered. TTFB local-render-only.
  4. Browser paints; placeholder visible as loading state.
  5. HTMX fires GET `/fed.htmx?ref=...`.
  6. The consumer's adapter handles the second request: runs full pipeline (allowlist, cache, fetch, denylist, rebase, skin), returns HTML fragment.
  7. HTMX swaps fragment into placeholder.
- Result: page feels fast; federated content arrives shortly after shell.

### A.4 Failure paths

If konnexus.net is unreachable:
1. Fetch fails.
2. Federation client emits `CTN: error code: 502 origin: http://konnexus.net/... message: fetch failed`.
3. `skin/error.html` renders with visible attribution link ("Could not load content from konnexus.net").
4. **Page HTTP status stays 200** (§9.6 — `origin:` field distinguishes federation error from local error).
5. Rest of the stack — header, intro, footer — still renders normally.

---

## Appendix B — Implementation notes (reference, PHP)

Non-normative. Reflects the PHP reference implementation as of 2026-04-24.

### B.1 File layout (containerist-php)

- `core/lib/containerist/federation.php` — the federation client library. ~330 lines. Public surface: `federation_is_ref`, `federation_substitute_url`, `federation_resolve`, `federation_strip_denylisted_blocks`. Internal: LRU cache store (static inside `federation_cache_store`), per-request fetch chain (static inside `federation_fetch_chain`), HTTP primitive (`federation_http_get`), ttl derivation, error emission.
- `core/mods/stack-ctn.ctn.php` — stack composition. Body-line loop pre-checks for federation shape; federation refs short-circuit `stack_parse_ref_line` and route through `federation_resolve`. Deferred form emits `CTN: htmx-placeholder` targeting the fragment endpoint.
- `core/config.defaults.php` — the `FEDERATION_*` constants (allowlist, cache enable, timeout, max size, default TTL, cache size, depth limit, user agent, block denylist).
- `core/renderers/acts/error.php` — `origin:`-sensitive branch that keeps federation errors from mutating page HTTP status (§9.6).
- `skin/error.html` — renders `{{#origin}}` attribution block per §9.5.
- Fragment endpoint: a consumer-side mod (e.g. `fed.ctn.php` or similar) that accepts `ref` as `@in`, invokes `federation_resolve`, skinner-renders the CTN output, returns the HTML fragment. URL: `/fed.htmx?ref=<urlencoded-producer-url>`.

### B.2 Allowlist representation

```php
define('FEDERATION_ALLOWLIST', array(
  'http://konnexus.net',
));
```

Scheme + host exactly. Port implicit (80 for http, 443 for https). No wildcards.

### B.3 Fetch primitive

`curl_exec` with `CURLOPT_TIMEOUT=FEDERATION_TIMEOUT`, `CURLOPT_CONNECTTIMEOUT=FEDERATION_TIMEOUT`, `CURLOPT_FOLLOWLOCATION=false` (redirects rejected in v2), `CURLOPT_USERAGENT`, `CURLOPT_ENCODING=''` (transparent gzip), `CURLOPT_HEADERFUNCTION` for header capture, `CURLOPT_WRITEFUNCTION` for size-guarded body accumulator. Size overflow via callback-return-zero; `CURLE_ABORTED_BY_CALLBACK` distinguished from transport errors via an `$over` flag.

### B.4 Error block serialization

```php
function federation_emit_error ($code, $origin, $message, $detail = '') {
  // strips newlines from origin/message, then:
  // CTN: error\ncode: <int>\norigin: <url>\nmessage: <msg>\n---\n<detail?>
}
```

The `origin:` field is load-bearing (§9.2, §9.6) — it distinguishes federation errors from local errors for the `error` act.

### B.5 Cache keying

Canonicalize URL (parse, sort query params, rebuild) + `|` + active rebase policy. Produces keys like `http://konnexus.net/notes.ctn?term=stitches|to-consumer`. No sha256 hash — human-readable keys make debugging cheaper.

### B.6 Rebase implementation

Regex-based. Navigational patterns (Markdown `\[…\]\(/…\)`, HTML `<a href="/…">`, `<form action="/…">`, frontmatter `url|href|canonical: /…`) and resource patterns (Markdown `!\[…\]\(/…\)`, HTML `<img src>`, `<link href>`, `<script src>`, `<iframe src>`, `<video poster>`, `<object data>`, `srcset`) each handled by a dedicated pass. The `<link>` vs `<a>` distinction for `href` is done via bounded lookbehind to the enclosing tag name.

### B.7 Block denylist

Text-based strip. Split response by `CTN:` headers; drop blocks whose type is in `FEDERATION_BLOCK_DENYLIST` (default `['redirect', 'session', 'flash', 'content-type']`). Applied before cache store.

### B.8 Injectable fetcher (test seam)

`federation_resolve($url, $per_line_args = [], $fetcher = null)` accepts a callable in the third position for unit testing. Signature matches `federation_http_get()`. Allowlist and cache paths run identically under injected fetcher; allows exercise of 413 / 415 / 504 / 304 / arbitrary status without a live producer.

### B.9 Deferred ports

- `containerist-ts`: implementation to follow.
- `containerist-go`: implementation to follow after TS.

---

*This spec reflects decisions made and shipped as of v2.0. Amendments land by pull request with forcing-use-case documentation.*
