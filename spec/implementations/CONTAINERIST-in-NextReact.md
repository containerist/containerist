# Containerist in Next.js + React

document_version: 0.2
status: draft (4.x; pre-5.0)
implementation_name: conti
implements: CTN-SPEC 1.2, STACK-SPEC 2.0 *(STACK-SPEC archived at framework 5.0; this `conti` impl is at 4.x and pinned to that spec until it migrates)*
drops: FEDERATION-SPEC, IN-SPEC, most of ACTS-SPEC

*Sidecar implementation notes for a browser-side realization of
Containerist, hosted by Next.js and rendered with React. Companion to
`CTN-in-TS.md` / `archive/4.x-stack-format/STACK-in-TS.md` (when those
exist at block-renderer scope); this document is the top-level briefing
for the `conti` implementation. Filename preserved from the previous
`containerist-next-react` naming because it describes the runtime
family, not the implementation's identifier.*

*5.0 migration note: Containerist 5.0 (2026-05-21) supersedes STACK-SPEC
with `PLACE-SPEC.md`. The `conti` impl is at 4.x and continues to read
`STACK-SPEC 2.0` (now at `archive/4.x-stack-format/STACK-SPEC.md`).
When `conti` migrates to 5.0, this document's "implements" line, layout
diagram, and § What it implements should update to reference
`PLACE-SPEC.md` and the `.place` file extension. The `.txt` / `.stack`
file references throughout this doc remain operative for the 4.x impl.*

---

## What this is

A browser-side realization of Containerist. A Next.js app acts as the
static harness; a small TypeScript runtime inside it acts as the
Webstacker. The Webstacker resolves a URL to a stack file, fetches the
stack and its referenced containers, parses CTN, and dispatches each
block to a React renderer. No backend logic at runtime. No build-time
CTN resolution. `.ctn` and `.txt` files are the authority; they are
fetched and parsed by the client at runtime.

Host: Next.js (dev server, build, static export). The runtime does not
depend on Next.js APIs beyond the catchall route and static asset
serving; in principle the same runtime would work under Vite or any
static host. The name commits to Next.js because that is the harness
it is built for and tested against.

Skin language: React. Committed because the target environments
are React-based UI libraries. Swapping React for another
view library is a new implementation (`containerist-next-solid`,
`containerist-vite-preact`, etc.), not a patch to this one.

## What it implements (normatively)

- **CTN-SPEC 1.2.** Byte-exact framing, YAML core schema frontmatter,
  flat block list, implicit `standard` for headerless files.
- **STACK-SPEC 2.0.** Filename-to-URL matching with wildcard priority,
  `@args` frontmatter, `{{N}}` / `{{name}}` substitution, per-line
  args. `.html` alias-default suffix.
- **Skin act.** The default act. Every block whose `type` has a
  registered renderer dispatches there; unregistered types fall
  through to a `standard` renderer.

## What it defers (named, not implemented in v0)

- **`redirect` act.** Will bind to `location.assign(to)`. Add when a
  container first needs it.
- **`flash` act.** Will bind to `localStorage` with a one-shot read
  contract mirroring the PHP arg-lifecycle. Add when auth-shaped
  flows appear.
- **`title` act.** Will bind to `document.title`. Trivial; add on
  first need.

## What it drops (non-goals)

- **FEDERATION-SPEC.** No cross-origin CTN fetching, no fed-rebase, no
  deferred federation, no allowlist. If federation becomes necessary
  in this environment, that is a new implementation, not a patch.
- **Realm diversity.** No CLI Stacker, no MCP Stacker, no in-process
  invocation story. The browser is the only realm.
- **`error` / `content-type` / `session` acts.** `error` collapses to
  a render path. `content-type` is meaningless client-side. `session`
  is out of scope until a concrete auth flow forces it.
- **Mods as code.** Containers in this implementation are static
  `.ctn` files. A future extension may add "browser mods" (TS
  functions returning CTN strings), but the v0 surface is static
  files only.

## Layout

Inside the Next.js project root:

    public/
      content/**/*.ctn      static CTN files, served as-is
      stacks/*.txt          stack files, STACK-SPEC grammar
    src/
      lib/ctn.ts            CTN parser (~100 lines)
      lib/stack.ts          STACK resolver (~80 lines)
      lib/stacker.tsx       URL -> stack -> blocks -> render
      blocks/<type>.tsx     one renderer per CTN block type
      blocks/registry.ts    { [type]: Renderer } lookup table
      app/[[...slug]]/page.tsx   catchall client route; delegates
                                 to <Stacker path={...} />

Stack files and CTN files are byte-portable to any other Containerist
implementation. Swapping this runtime for `containerist-php` should
produce semantically equivalent output for the same `content/` and
`stacks/` directories.

Content and stack files live under `public/` so Next.js serves them
as static assets over `fetch()`. This is deliberate: it keeps the
files at a URL, preserving CTN-as-data authority. Inlining them as
TypeScript imports or baking them into a build-time JSON bundle
would reduce `.ctn` to a build input and silently fork the format.

## Block renderer contract

Each block renderer is a React component with the shape:

    type BlockProps = {
      fields: Record<string, unknown>;  // YAML-parsed frontmatter
      body: string;                     // opaque text, typically Markdown
    };

    export default function ArticleBlock({ fields, body }: BlockProps) {
      // ...
    }

### Body rendering

Per CTN-SPEC §1 and §4.8, body is opaque to CTN; each block type decides
how to render it. The canonical rendering for prose is Markdown → HTML.
The PHP reference uses Parsedown + Mustache's raw `{{{body}}}` slot. The
React analogue in this implementation is `marked` + `dangerouslySetInnerHTML`
(see `src/lib/markdown.ts`). The `standard` block uses this path by default;
other block types MAY render body differently (plain text, pre-formatted,
HTML fragment pass-through) at the renderer's discretion.

No sanitization is applied in v0.1. Content comes from local `.ctn` files
trusted by origin. If CTN ever arrives from an untrusted source (federation,
user-submitted), add DOMPurify inside `renderMarkdown`, not at call sites.

Constraints on renderers (enforced by convention, lint-checkable):

- No `children` prop. Blocks are opaque to their neighbors.
- No React context providers or consumers inside a block.
- No sibling-awareness. A block does not know its index, stack
  position, or neighboring block types.
- Client-side interactivity is permitted (`"use client"` is allowed
  per block) but discouraged by default.

One block type = one file under `blocks/`. The file's default export
is the renderer. The registry maps `type` string to the component.

## Conformance

A `conti` implementation is conformant if, for the
shared conformance corpus at `containerist/conformance/`:

- Its CTN parser produces equivalent block lists (per CTN-SPEC §8).
- Its STACK resolver selects the same stack file for the same URL,
  and assembles the same ordered container list with the same bound
  args.
- Its skin-act output is structurally equivalent to the PHP
  reference's skin-act output, modulo the skin template language
  (React components vs. Mustache pairs).

Federation, non-trivial `@in` validation, and acts beyond `skin` are
out of conformance scope for this implementation.

## Known costs

- **G4 test fails.** React plus hydration between URL and first paint.
  Owned, not defended. Prerendering via `next export` is a future
  mitigation if this ever leaves sandbox.
- **No SEO / no-JS fallback.** Same root cause.
- **No `@in` validation at runtime.** Block fields are consumed as
  untyped YAML values. Renderers are expected to handle missing or
  malformed fields gracefully. A stricter `@in` layer may be added
  later; it is not load-bearing for this implementation's use case.

## Version history

- 0.1 (2026-04-24) — initial scope declaration. Name finalized as
  `containerist-next-react` after rejecting `containerist-browser`
  (too generic) and `containerist-njsreact` (readability).
- 0.2 (2026-04-24, same day later) — implementation renamed to
  `conti` after a session-long clarification with the author that
  pared the subset down (dropping federation, mods, acts, htmx,
  URL schemes for raw CTN/Stack access, and Mustache skins; keeping
  CTN + Stack + static files + the CTN/Stack distinction; swapping
  skins to React components). The name is a deliberate diminutive
  to signal that this is a subset, not a full Containerist peer.
  See `logs/2026-04-24-conti-birth.md` and `conti/`
  for the working implementation, spec (`conti.md`), and heritage
  doc (`containerist-heritage.md`). This filename kept.
