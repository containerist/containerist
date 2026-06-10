# SKIN-in-TS — TypeScript Implementation Guide for Skins

**Status:** Informative. No canonical `SKIN-SPEC.md` exists yet (referenced as "SKIN-SPEC (future)" in STACK-SPEC §10). This document describes the TS impl's skin conventions. PHP and Go impls keep their own; the skin layer is the one place where the three impls diverge.

**TS-only scope.** Skins are CSS + HTML — presentation. CTN (the wire format) and STACK (the composition format) stay language-independent. Skin-level divergence does not break portability of content.

---

## The skin pattern (TS)

One `skin/<type>.html` per CTN block type. Mustache template syntax. Styling is Tailwind utility classes carried in the HTML. No paired `.css` file.

Example — `skin/standard.html`:

```html
<article class="standard max-w-2xl my-16 mx-auto px-6 font-sans leading-normal text-neutral-800">
  {{#title}}<h1 class="text-2xl font-semibold mb-4 mt-0">{{title}}</h1>{{/title}}
  <div class="body whitespace-pre-wrap">{{{body}}}</div>
</article>
```

Rendered by the `skin` act (see `ACTS-in-TS.md` §skin.ts). Render context: `{ ...block.fields, body: block.body }`. `{{field}}` is HTML-escaped, `{{{body}}}` is raw.

## Build setup

**Tailwind v3** with the standalone CLI. v3 rather than v4 because v4's Oxide engine requires Node 20+ and this project runs on Node 18. Revisit on runtime upgrade.

**Input file** — `styles/tailwind.css`:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

**Config** — `tailwind.config.js` declares the content paths Tailwind scans for class names:

```js
module.exports = {
  content: [
    './skin/**/*.html',
    './modules/**/*.ts',
    './app/**/*.{ts,tsx}',
    './src/**/*.ts',
  ],
  theme: { extend: {} },
  plugins: [],
};
```

**Output** — compiled CSS lands at `public/tailwind.css` and Next.js serves it at `/tailwind.css` (public/ takes precedence over the catch-all route handler).

**Scripts** (`package.json`):

```json
"dev": "tailwindcss -i ./styles/tailwind.css -o ./public/tailwind.css --watch & next dev",
"build": "tailwindcss -i ./styles/tailwind.css -o ./public/tailwind.css --minify && next build",
"tailwind:build": "tailwindcss -i ./styles/tailwind.css -o ./public/tailwind.css",
"tailwind:watch": "tailwindcss -i ./styles/tailwind.css -o ./public/tailwind.css --watch"
```

Dev loop: `npm run dev` runs the Tailwind watcher and Next's dev server in parallel. Build: Tailwind compiles first, then Next. The compiled CSS is gitignored (`public/tailwind.css`).

## Why the standalone CLI (not the Next/PostCSS integration)

The `app/[[...slug]]/route.ts` route handler returns raw `Response` objects. It bypasses `app/layout.tsx` entirely, so Next's usual CSS import pipeline (import `globals.css` from a layout → hashed stylesheet URL) doesn't apply. Decoupling Tailwind from Next's bundler via the standalone CLI is the cleanest fit: output to `public/`, link from the page shell, stable URL.

## The page shell — `skin/stack.html`

Rendered by `renderPageShell(root, head, body)` in `src/adapters/web/WebAdapter.ts`. Wraps the `body_blocks` and head slot into the full HTML document.

Baseline shell (what ships with `containerist-ts` as the default):

```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{#head.title}}<title>{{head.title}}</title>
{{/head.title}}<link rel="stylesheet" href="/tailwind.css">
</head>
<body class="min-h-screen bg-white text-neutral-900 antialiased">
{{{body}}}
</body>
</html>
```

Sites override by dropping their own `skin/stack.html` into their repo root. Custom shells must reference `/tailwind.css` (or provide their own compiled stylesheet at a different URL).

## Mods emitting HTML

Mods that emit a `CTN: standard` block with HTML body can carry Tailwind classes in the body:

```ts
return `CTN: standard
---
<div class="rounded-lg bg-amber-50 border border-amber-300 p-4">
  <strong class="text-amber-900">${safe}</strong>
</div>
`;
```

The body flows through `{{{body}}}` raw; classes survive. Tailwind's `./modules/**/*.ts` content path picks up classes in mod source files at build time.

## Cross-impl note

Skin HTML files in PHP live at `containerist-php/skin/<type>.html` and use traditional `class="name"` + a paired `<type>.css` file with actual CSS rules. Go is similar.

The TS impl's skin HTML diverges from PHP's — same Mustache template placeholders, different class names, different CSS delivery. **This is the one layer where the three impls are not byte-identical.** Content (mods, stacks, CTN) portability is preserved.

## Authoring checklist

- Skin files live at `containerist-ts/skin/`.
- File name is `<block-type>.html` where `<block-type>` matches the `CTN: <type>` header the skin renders.
- Use Tailwind utility classes directly in class attributes.
- Mustache `{{field}}` for field values (auto-escaped); `{{{body}}}` for body (raw).
- No `<!doctype>`, `<html>`, `<head>`, `<body>` — those live in `skin/stack.html`. Skin files emit fragments.
- New class used in a skin is automatically included on the next Tailwind recompile (watcher in `npm run dev`, or explicit `npm run tailwind:build`).

## Escape hatch — for the rare case

There's no built-in plumbing for per-type `.css` siblings any more (removed 2026-04-23 along with `inlineStylesFromTypes`). If you need custom CSS that Tailwind doesn't cover — `@keyframes`, cascade layers, container queries, print stylesheets — two options:

- **(a) Add to `styles/tailwind.css`.** Rules defined in the Tailwind input file are preserved in the compiled output. Global but straightforward.
- **(b) Add a second `<link>` in `skin/stack.html`.** Compile or write a separate stylesheet, drop into `public/`, link from your custom stack shell.

Both keep the work visible in one or two files. Neither reintroduces per-type cascade complexity.

## Conformance and testing

No conformance fixtures for skins — rendering is presentation-layer and visually checked. Integration tests at `test/integration-web.test.ts` verify that skin output reaches the response body intact. A failing skin surfaces as a markup or styling regression in those assertions.

## Related

- `/containerist-ts/FRONTEND-POLICY.md` — what's allowed at the frontend layer in TS.
- `ACTS-in-TS.md` §skin.ts — where skin rendering is invoked in the dispatch loop.
- `CTN-in-TS.md` — the block shape skins receive.
- `PLACE-in-TS.md` — how stacks reference skin'd block types.
