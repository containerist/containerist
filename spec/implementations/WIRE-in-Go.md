# WIRE in Go — implementation notes

Sidecar to `WIRE-SPEC.md` (0.1 draft) for the Go reference of the wire adapter: `containerist-go/adapters/spa` + `containerist-go/renderers/placespec`. The spec is authority; this file records how Go realizes it and the Go-specific conventions a maintainer should know. Pattern-sibling of `CTN-in-Go.md` / `IN-in-Go.md`.

## Package naming

The Go package is **`spa`** — it predates the spec's `wire` name (it was written for the rook-flasher's "floater" client before the surface was understood as serving curl and device pipelines equally). Renaming `adapters/spa` → `adapters/wire` is open housekeeping (WIRE-SPEC §12); until then: *spec says wire, Go says spa, same thing.*

## Layer split

The adapter composes two realm-free renderers, mirroring the 5.2 hourglass:

| Spec section | Go home |
|---|---|
| §3 routing, §6 PRG, §7 lifecycle, §8 sessions, §9 status | `adapters/spa` (`spa.go`, `composed.go`, `spec.go`) |
| §4 structure block (`CTN: place`, refresh lift, arg resolution) | `renderers/placespec` |
| §5 verbatim relay | `renderers/raw` (shared with the web adapter's `.ctn`/`.raw`/`.placectn`) |
| session crypto, bearer, multipart, query/POST facts | `adapters/common` (shared with `adapters/web`) |

The raw renderer labels its output `text/plain`; the adapter re-labels to `text/ctn; charset=utf-8` on this surface (spec §9.1) — that re-label lives in `composed.go`, not the renderer.

## Realization notes, by spec section

- **§6 `_return` consumption.** The adapter deletes `_return` from the args map *before* invoking the mod (`composed.go`). This is load-bearing because of the registration-mode note below: schema filtering alone would not stop `_return` reaching a schema-less mod. Pinned by `TestContainer_POST_ReturnNotForwardedToMod`.
- **§7 IN filtering is per registration mode.** `Core.Ctn` filters args through `ResolveIn` only for mods registered with a schema (`RegisterModWithSchema`). A mod registered via bare `RegisterMod` receives the **full merged map unfiltered** — session facts, `bearer`, query, POST, `__file:*` included. Consequence for instance authors: *mutation endpoints and any mod reading adapter-injected facts should be schema-registered*; bare registration is for arg-less display mods. (The flasher instance follows this split.)
- **§7 realm reservations.** `q` and `trace` are dropped from query/POST facts in `adapters/common.RequestFacts` — the Go realization of ACTS-SPEC §6's per-realm reserved names, inherited by the wire because the machinery is shared with the web adapter. `refresh` (per-line, §4.3) and `_return` (§6) are the wire's own reservations.
- **§7 multipart cap.** `Adapter.MaxUploadBytes`; zero falls back to `common.MaxMultipartBodyBytes` (32 MiB — the spec's advisory default). Uploaded files surface as `__file:<field>` args of type `containerist.UploadedFile`.
- **§8 session cookie.** Encrypted payload via `adapters/common`; `Adapter.SessionTTL` (default 14 days; zero = browser-session), `Adapter.SecureCookies`. The sliding-window rewrite happens when the payload changed *or* a session already existed (`persistSession`).
- **§9.2 the narrow 404 promotion is a string heuristic.** `isContainerNotFound` matches `IsErrorCTN` + the Core's exact phrase shape (`container '…' not found`). The Core does not type its own errors distinctly from mod-emitted ones on the wire, so a mod that emits the *exact* Core phrase would be promoted — indistinguishable by construction, accepted. A mod-emitted error that merely contains "not found" (e.g. `system not found`) is **not** promoted; pinned by `TestContainer_ModEmittedNotFoundError_StaysInBand`.
- **§4.4 `columns`.** Read from the place's raw frontmatter (`p.Raw["columns"]`) — i.e. tolerated by the Go place loader without being part of the typed `Place` struct. This is the implementation shape of the spec's grammar caveat: the key rides along untyped until PLACE-SPEC decides its fate.

## Conformance witness

`go test ./adapters/spa/ ./renderers/placespec/` is the executable form of WIRE-SPEC §11. The MUST-level pins, by point:

1. §3 surface — `TestUnknownSuffix_Is404`, `TestAPIPrefix_OutsideIs404_InsideResolves`
2. §4 structure — `TestSpec_ResolvesPlaceAndReturnsCTN`, `TestSpec_IndexPlace`, `TestSpec_NoMatchIs404`; `placespec`: `TestRenderPlace_ResolvesArgsAndLiftsRefresh`, `TestRenderPlace_RegionsAndContainersInOrder`
3. §5 verbatim — `TestContainer_GET_WithArgs`, `TestContainer_StaticCopy`, `TestContainer_POST_P2Mutation`, `TestContainer_Multipart_Upload`
4. §6 PRG — `TestContainer_POST_NoJSFormPRG`, `TestContainer_POST_NoReturn_VerbatimCTN`, `TestContainer_POST_OffOriginReturn_Ignored`, `TestContainer_POST_ReturnNotForwardedToMod`
5. §7 lifecycle — `TestSession_BearerReachesMod` (+ `adapters/common` tests for merge order)
6. §8 sessions/flash — `TestSession_SlidingWindowAndAnonymous`, `TestFlash_PRGStash_InjectedOnceThenCleared`
7. §9 status — `TestContainer_NotFoundIs404`, `TestContainer_ModEmittedNotFoundError_StaysInBand`, `TestEvents_NotImplemented`

First consumers (living conformance evidence): the rook-flasher instance — `flasher-rich` (browser client over `.spec`/`.ctn`), the on-rook screen pipeline, and the `curl` examples in its README.
