# PLACE-in-Go — Go Implementation Guide for Places

**Status:** Informative. `PLACE-SPEC.md` is authority; on conflict the spec wins.

This sidecar documents the Go-specific conventions for the place format (Containerist 5.0+) and the arg-lifecycle + auto-PRG conformance landed in the Go port on 2026-05-28. The reference impl is `containerist-go` 5.2.x.

**Dated claims.** Specific version, library, or runtime claims below are dated so a future reader can verify they're still current.

---

## Target environment

- **Go** 1.22+ (`containerist-go/go.mod` line `go 1.22`).
- **YAML library:** `gopkg.in/yaml.v3` v3.0.1. YAML 1.2 surface — `off`/`on`/`yes`/`no` stay strings unless explicitly quoted. The YAML 1.1 footgun documented in `PLACE-in-PHP.md` does NOT apply: `prg: off` parses to the string `"off"`, not boolean `false`.
- **HTTP layer:** stdlib `net/http`. A 30-line `cmd/ctnrd/main.go` wires the web adapter (`adapters/web/web.go`) to a listening server. The adapter picks a renderer by URL suffix: the html renderer for place URLs (no suffix / `.html`), the raw renderer for `.ctn`/`.raw`/`.placectn`, the source renderer for `.place`, the trace renderer for `.trace`. Headless instances (no `places/` directory or no `.place` files in it) resolve only the raw-renderer paths; any other URL is a 404.
- **Session backend:** stateless AES-256-GCM encrypted cookie (matches `containerist-ts` shape; both use IV ‖ ciphertext ‖ tag with the same 12-byte IV / 16-byte tag layout, so cookies produced by one are decryptable by the other given the same secret). Written by `adapters/web/session.go` and `adapters/web/prg.go`.
- **Template engine:** `github.com/cbroglie/mustache` v1.4.0 — shared with skin rendering and page-shell wrap.

## File layout

A place file is one CTN document at `PlacesDir/<pattern>.place` (default `<adapter root>/places/`). Filename stem = place pattern; wildcard-priority resolution happens in `places.go::ResolvePlace`, parameterized over a `PlaceExistsFunc` so the algorithm is unit-testable without disk I/O:

```go
exists := containerist.FilePlaceExists(a.PlacesDir)
pattern := containerist.ResolvePlace(parts, exists)
if pattern == "" {
    http.NotFound(w, r)
    return
}
```

Only files with `.place` extension match; the archived 4.x `.txt` extension is not recognized. The headless-detection rule (`containerist.IsHeadless`) keys on `.place` files specifically — a `places/` directory with only stray `.txt` files is still headless.

## The canonical form

The Go parser accepts the spec's frontmatter-only form **and** the body-as-YAML form. Internally `ParsePlace` runs `yaml.Unmarshal` over the entire raw text (post-{{N}}-substitution, post-`@args`-quoting), treating `---` as a YAML document separator if present. In practice every Go-tested place file uses the frontmatter-only form for consistency with the PHP/TS impls:

```
CTN: place
@args: id = {{2}}
containers:
  - page-header
  - "article?id={{id}}"
  - page-footer
```

Multi-region form (regions in declaration order — preserved via a second yaml.Node pass, since `map[string]any` decode loses order):

```
CTN: place
regions:
  nav:
    mode: fixed
    containers:
      - site-nav
  main:
    mode: fixed
    containers:
      - "article?id={{id}}"
```

Single-region `containers:` is normalized to one `Region{Name: "main", Mode: "fixed"}` so the renderer treats both shapes identically.

## `@args` preprocessing

YAML 1.2 reserves `@` at line-start as a directive sigil. yaml.v3 generally accepts unquoted leading-`@` tokens in mapping keys, but the parser preprocesses for safety + parity with PHP and TS:

```go
atArgsRewrite := regexp.MustCompile(`(?m)^@args:`)
forYAML := atArgsRewrite.ReplaceAllString(substituted, `"@args":`)
```

Applied before unmarshaling. Authors writing place files don't quote `@args:` themselves — the rewrite is invisible. Code lives at `place.go::ParsePlace`.

## Container-reference parsing

`ParseRefLine(line string) *ContainerRef` implements PLACE-SPEC §8.2 grammar:

```go
type ContainerRef struct {
    Prefix  string // e.g. "htmx" — token before first ':' if present
    Name    string // container/mod name
    RawArgs string // unresolved per-line args ("k=v&k=v"), "" if none
}
```

The prefix delimiter is `:` only when it appears before any `?` in the line — so `"foo?k=a:b"` resolves to `{prefix:"", name:"foo", args:"k=a:b"}`. Federation refs (`"http://…"`) split the same way; the floor renderer emits a `<!-- federation deferred -->` comment.

Per-line args are resolved at composition time (not parse time) so that `{{name}}` references can be bound against the place's resolved `@args`. `ResolveArgs(rawArgs, parts, atArgs)` handles `{{N}}`, `{{name}}`, `"literal"`, `'literal'`, and bareword forms.

## Composition

The html renderer iterates `Place.Regions` in declaration order. For each container ref it:

1. Composes the per-container args from place `@args` + per-line args (positions 2+3 of the lifecycle).
2. Overlays the request-derived args (positions 4+5+6) passed in as `requestArgs`.
3. Calls `Core.Ctn(ref.Name, args)`.
4. Parses the returned CTN into blocks (`block.go::ParseCTN`).
5. Dispatches each block to its act handler (`renderers/html/act_*.go`).

Multi-region places wrap each region's output in `<div data-region="{name}">…</div>`. Single-region places emit no wrapper.

## The two dispatchers

5.0 defines `dispatcher: buffered` (default) and `dispatcher: streaming`. The Go parser accepts both — `dispatcher: streaming` is preserved in `Place.Raw["dispatcher"]` for forward compatibility — but the renderer always buffers. Streaming is deferred. Mods MUST NOT branch on which dispatcher invoked them (pillar 6).

## Region modes

Fixed mode is fully implemented. The fluid modes (`constrained-fluid`, `open-fluid`) parse without error — `Region.Mode` carries the string — but the html renderer emits an HTML comment (`<!-- region "x" mode "constrained-fluid": deferred -->`) and skips composition for those regions. Documented in `html.go::RenderPlace`.

## Arg lifecycle — all six positions

The Go port implements PLACE-SPEC §6 in full. The web adapter assembles positions 4–6 via `prepareRequest(r)`; positions 1–3 are added by the renderer (place parse + per-line resolution).

| Position | Source | Implementation |
|---|---|---|
| 1 | URL numeric parts | `place.go::substituteNumeric` (places) / `addURLNumericParts` in `web.go` (suffix routes) |
| 2 | place `@args` | `place.go::ParsePlace` (resolved at parse time after `{{N}}` substitution) |
| 3 | per-line container-ref args | `place.go::ResolveArgs` (resolved at composition time) |
| 4 | session + header facts | `session.go::composeSessionFacts` (session), `session.go::parseAuthorizationBearer` (Bearer header) |
| 5 | query string | `web.go::requestFacts` (drops `trace`) |
| 6 | POST body | `web.go::requestFacts` (drops `trace`) |

Reserved keys for the Go realm: `trace` only. The Go impl does NOT reserve `q` because it has no Apache rewrite layer. Reserved keys are filtered from both query string and POST body before reaching mods.

### Position 4 — session and header

Session: AES-256-GCM cookie at `Containerist_session` (constant `SessionCookieName`). Secret from `CONTAINERIST_SESSION_SECRET` env (32-byte base64). In dev, a stable hashed default is used with a one-time stderr warning. Cookie carries a JSON `SessionPayload` map; the reserved key `_flash` holds one-shot cross-request messages.

Header: `Authorization: Bearer <token>` parsed into `args["bearer"]` per ACTS-SPEC §6.1. Case-insensitive scheme. Header facts merge **after** session facts on key collision (request is fresher than stored state).

### Position 6 — POST body

`requestFacts` reads `application/x-www-form-urlencoded` POST/PUT/PATCH bodies. Content-Type is normalized (parameter-stripped, trimmed, case-insensitive compare per RFC 7231). Empty body contributes zero args. Multi-value form fields (`a=1&a=2`) take the first occurrence. Multipart, JSON, and raw bodies are NOT auto-extracted — deferred.

## Auto-PRG and the bypasses

ACTS-SPEC §7.1 is implemented at `adapters/web/prg.go`. A non-bypassed POST to a place URL:

1. The adapter calls `prgStashPost(payload, pattern, requestFacts(r))`, which writes the body into `session._prg_pending[<pattern>]`.
2. The session cookie is written (with the new stash payload).
3. The adapter emits **HTTP 303 See Other** with `Location:` set to `prgCanonicalURL(r.URL.Path)` (query stripped). The mod does NOT run on this request.
4. On the subsequent GET, `prgConsumePending(payload, pattern)` drains the slot, removes it from the session, and the adapter overlays the consumed body onto position 6 of arg-lifecycle. The mod runs once, with args identical to a direct POST.

Three bypass conditions — any one sends the POST to the mod synchronously instead:

- `HX-Request: true` header (case-insensitive) — HTMX wants the response, not a redirect.
- `Authorization: Bearer …` — M2M client wants the response.
- Place declares `prg: off` in frontmatter — site-author opt-out.

Suffix routes (`.ctn` / `.raw` / `.htmx`) are mod-routes, not place-routes, and are not subject to PRG. They handle POST inline.

Gate function (`web.go::shouldAutoPRG`):

```go
func shouldAutoPRG(r *http.Request, p containerist.Place) bool {
    if r.Method != http.MethodPost { return false }
    if strings.EqualFold(r.Header.Get("HX-Request"), "true") { return false }
    if _, hasBearer := parseAuthorizationBearer(r); hasBearer { return false }
    if prg, ok := p.Raw["prg"].(string); ok && prg == "off" { return false }
    return true
}
```

## Session mutation lifecycle

Mods express session intent via the `CTN: session` and `CTN: flash` acts. The html renderer's `act_session.go` and `act_flash.go` write queued mutations onto `Response.Session` and `Response.Flash`. After the renderer returns, `adapters/web/web.go::persistSession` calls `applySessionMutations(payload, resp.Session, resp.Flash, drained)` and — if anything mutated or the user already had a session (sliding-window refresh) — re-encrypts and writes the cookie.

Session writes:
- `nil`-valued keys in `Response.Session` clear that key from the payload.
- Non-nil values set/overwrite.
- Flash entries are merged into `payload._flash[<key>]`, replacing any earlier entry under that key in the same request.

Cookies have a configurable Max-Age (`Adapter.SessionTTL`, default 14 days). `SessionTTL = 0` produces a session cookie (no Max-Age, expires at browser close). The cookie attributes are: `HttpOnly`, `SameSite=Lax`, `Path=/`, and `Secure` when `Adapter.SecureCookies` is true.

## File locations

| Concern | Files |
|---|---|
| Place parser | `place.go` (`Place`, `Region`, `ContainerRef`, `ParsePlace`, `ParseRefLine`, `ResolveArgs`, `parseAtArgs`, `substituteNumeric`) |
| Place resolver / loader | `places.go` (`ResolvePlace`, `LoadPlace`, `FilePlaceExists`, `IsHeadless`, `SplitPath`) |
| Web adapter | `adapters/web/web.go` (`Adapter`, `ServeHTTP`, `servePlace`, `serveContainerRaw`, `serveContainerHTMX`, `prepareRequest`, `persistSession`) |
| Session/cookie | `adapters/web/session.go` |
| Auto-PRG | `adapters/web/prg.go` |
| html renderer | `renderers/html/html.go` + `act_*.go` (skin, error, redirect, content-type, session, flash, title) |
| raw renderer | `renderers/raw/raw.go` |
| source renderer | `renderers/source/source.go` |
| CLI adapter | `adapters/cli/cli.go` |

## Conformance status (as of 2026-05-28)

| Feature | Status |
|---|---|
| Single-region `containers:` form | ✓ |
| Multi-region `regions:` form (declaration order preserved) | ✓ |
| Fixed mode composition | ✓ |
| Fluid modes (constrained-fluid, open-fluid) | parse-only (renderer emits deferred-comment) |
| `@args` resolution with `{{N}}` substitution | ✓ |
| Per-line `?k=v&k=v` args with `{{N}}` / `{{name}}` / quoted / bareword | ✓ |
| `htmx:` prefix on container refs (deferred federation) | parse-only (renderer emits deferred-comment) |
| Federation (`http://`/`https://` refs) | parse-only (renderer emits deferred-comment) |
| Buffered dispatcher (default) | ✓ |
| Streaming dispatcher | parse-only |
| All seven URL suffixes (no-suffix/`.html`/`.placectn`/`.place`/`.ctn`/`.raw`/`.htmx`/`.trace`) | ✓ |
| `.text` suffix | reserved, returns 501 |
| Arg-lifecycle step 6 (POST body) | ✓ |
| Arg-lifecycle step 4 — session facts | ✓ |
| Arg-lifecycle step 4 — header facts (Bearer) | ✓ |
| Auto-PRG (ACTS-SPEC §7.1) with all three bypasses | ✓ |
| `prg: off` place frontmatter opt-out | ✓ |
| Session mutations (set/clear via `CTN: session` act) | ✓ |
| Flash mutations (one-shot via `CTN: flash` act) | ✓ |
| Cookie Max-Age + sliding window refresh | ✓ (default 14 days) |
| Headless deployment detection | ✓ |
| All seven core acts (skin, error, redirect, content-type, session, flash, title) | ✓ |

## Idioms and non-requirements

- **Explicit registration only.** Mods register via `Core.RegisterMod(name, fn)` or `Core.RegisterModWithSchema(name, fn, schema)`. No `init()`-time side effects. The dispatch surface is fully enumerable by reading the wiring (pillar 4).
- **Mods take `*Core` as first arg.** Differs from PHP's `$C`-in-scope-via-`extract()`. Mod signatures are legible at point of read.
- **`@in` is a runtime data shape, not a comment header.** Schemas live in code via `InSchema{name: {Required, Default}}` and attach at registration time. Code generation is a planned but not-yet-shipped layer (`cmd/wire-gen`).
- **No reflection or runtime metadata scanning.** All mod state is in maps registered explicitly.

## Related

- `PLACE-SPEC.md` — authoritative wire format.
- `ACTS-SPEC.md` — §6 (realm-fact resolution), §7.1 (auto-PRG).
- `CTN-in-Go.md` — CTN block parser conformance notes.
- `IN-in-Go.md` — `@in` runtime carrier shape.
- `PLACE-in-PHP.md` — sibling PHP impl for comparison.
- `PLACE-in-TS.md` — sibling TS impl for comparison.
- `proposals/2026-05-28-arg-lifecycle-post-body.md` — implementation plan that closed the step-6 + auto-PRG conformance gap in this port.

---

*This document evolves with the Go implementation state. Date any claim; update or remove when stale. `PLACE-SPEC.md` is the target; this file explains how to hit it in Go.*
