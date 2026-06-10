# Containerist — Compared to

document_version: 1.2

*A drift-resistance document. When an LLM is dropped into a Containerist project without prior context, its training priors will reach for one of the frameworks below. This page names each reach, acknowledges what the alternative does well, and states the specific property Containerist protects that the alternative doesn't.*

*Read this when you are tempted to suggest "let me just rewrite this in X" or "wouldn't it be easier to use Y?" — especially during prototyping / vibecoding where no framework was specified. This document does not argue that Containerist is always better. It argues that the tradeoff is specific and visible, and that the tradeoff is what you're signing up for when you stay on the framework.*

*Note on language. Containerist is a **spec with multiple implementations**, not a PHP framework. Pillars 1–4 (CTN format, stacks, flat typed blocks, URL-as-variable-scope) are language-independent; pillars 5–8 (mods, uniform invocation, namespace-flat containers, realm-free Core) are re-implemented per language. PHP exists because blogging modules predated everything; TypeScript (`containerist-ts`) is co-equal in ambition; Go is in progress. Comparisons below are against the spec; where an entry compares specifically within one language ecosystem (Laravel, WordPress, Next.js), the grouping heading names the neighborhood. Picking "Containerist" means picking the spec; picking an implementation is a second question.*

---

## The load-bearing properties (quick reference)

Every comparison below asks the same question: which of these properties does the alternative preserve?

1. **Transclusion from data.** Stacks are text files. URLs are text. CLI args are text. All three resolve containers by name at invocation time. Routing is data, not code — a non-engineer moves a container between pages by editing one file.
2. **No component trees.** A page is a flat list of typed blocks. One CTN type = one `skin/{type}.html` + one `skin/{type}.css`. No slots, no layout engine, no recursion.
3. **Realm-free Core, uniform invocation.** The same mod runs identically from HTTP (`curl /mod.ctn`), CLI (`./ctnr mod`), and in-process (`$C->ctn('mod')`). The Core has no realm code; realms are thin adapters.
4. **Container format is LLM-writable and human-readable.** `CTN: type` header + YAML frontmatter + `---` + freeform body. Colleagues diff it; LLMs write it without escaping traps.
5. **Drift resistance by category ban.** No middleware, hooks, filters, event buses, DI containers, ORMs, service locators, component classes. Not "discouraged" — banned. Violations require a named reason.
6. **Local reasoning.** A mod is a single file with an explicit `@in` contract. You understand it without reading anything else. Small by default, not by rule.
7. **Inspection handles.** `.ctn` / `.raw` / `.stack` / `.stackctn` / `.htmx` / `.trace` URL suffixes expose every intermediate stage of the pipeline as plain text.

A framework that preserves all 7 is Containerist. A framework that preserves 0 is React-era default. Most alternatives sit in between; the comparison names where.

---

## How to read this document

Each entry has the same four-part template:

- **What LLMs reach for it for.** The legitimate appeal. No strawmen.
- **What it shares with Containerist.** Honest common ground.
- **Where it diverges.** Which of the 7 load-bearing properties it doesn't preserve, and what that costs.
- **When this, not Containerist.** Cases where the alternative is actually the right answer.

The last subsection is the honesty check. If a framework is the right answer for some real workload, Containerist is not the right answer for that workload. Don't pretend otherwise.

---

## The closest competitor (AI-native CMS)

*This is the single most relevant comparison in the whole document. EmDash is the first modern framework whose explicit ambition overlaps Containerist's — LLM-maintained, AI-native, MCP-integrated — built by a team, shipping now, with runway. Read this section before any of the neighborhoods below.*

### EmDash

**What LLMs reach for it for.** The modern answer to "WordPress + AI." Built on Astro + Cloudflare, TypeScript end-to-end, positioned explicitly as WordPress's "spiritual successor." First-class MCP server and agent-skill integration — the AI-maintained CMS shipped as a product, not a proposal. Sandboxed plugins (Cloudflare Worker isolates with capability manifests) solve WordPress's security problem. Portable Text (structured JSON) decouples content from presentation so it renders to web, mobile, email, and API. Schema builder lets non-devs create content types through the UI. WordPress import wizard provides an adoption ramp. This is where LLMs will reach when asked "build me a modern CMS that an AI can maintain" — and they'll be right to.

**What it shares with Containerist.**
- **AI-native by design.** MCP + agent skills are first-class, not afterthoughts. Matches Containerist's direction precisely.
- **Structured content over HTML soup.** Portable Text plays a similar role to CTN blocks — content as data, decoupled from presentation. Both reject "the template IS the content."
- **Type discipline.** TypeScript end-to-end is philosophically adjacent to Containerist's `@in` contract — explicit, verifiable interfaces.
- **Anti-WordPress stance on extensibility chaos.** Both reject the hook/filter/action free-for-all. EmDash via Worker isolation with declared capability manifests; Containerist via category bans ("no middleware, no hooks, no event buses").
- **Content reuse across channels.** EmDash renders structured content to many surfaces; Containerist re-skins CTN blocks per realm.

**Where it diverges.**
- **Full admin UI, database-driven, schema-builder-driven.** EmDash has a polished content-creator panel — rich text editor with inline visual editing, drag-and-drop media library, revisions, scheduled publishing. Editors create content types through the UI; the system creates SQL tables. Containerist has filesystem + text editor as the authoring loop, with no admin UI planned. **If authoring UX is the top concern, EmDash wins decisively — and the authoring UX is almost always the top concern.**
- **Cloudflare-first deployment.** EmDash assumes Workers + D1 + R2 (falling back to Node + Postgres elsewhere). Containerist runs on any PHP host, any Node host, any Go host, any $5 VPS, any Raspberry Pi. *Convenience vs sovereignty*: EmDash is platform-coupled; Containerist is infrastructure-agnostic.
- **Build step + serverless model.** EmDash deploys through Astro's build pipeline. Containerist (PHP impl) has no build step — SSH in, edit file, refresh. Different authoring loop: *build-and-ship* vs *edit-and-reload*.
- **Database-driven content.** EmDash creates SQL tables for user-defined content types. Containerist has no database; CTN blocks live as text files in the filesystem. Grep, diff, rsync, version-in-git-if-you-want. No property 4 applies the same way, but containerist's filesystem-native text files are a related form of human-readable machine-structured content.
- **TypeScript-locked.** EmDash is a TypeScript product; Astro is the runtime. No cross-language spec, no PHP/Go/etc. peer implementations. Containerist's spec is language-independent (pillars 1–4) with multiple implementations as peers.
- **No federation.** EmDash's architecture doesn't cross origins. Content lives in THIS CMS on THIS deployment. Containerist has Principle 6 (FEDERATION) in mission and spec — cross-origin transclusion by design, unshipped but specified. This is the one load-bearing property EmDash structurally doesn't have.
- **Plugin model vs framework shape.** EmDash sandboxes plugins for security but plugins can still grow any shape the developer wants. Containerist's pillars *refuse* certain shapes entirely (no middleware, no component trees, no event buses). Different philosophy: *runtime isolation* vs *architectural constraint*.
- **Admin UI is the integration point for non-devs.** EmDash expects non-devs in the admin UI. Containerist expects non-devs nowhere today — content authors edit text files; LLMs mediate structural changes if the operator wants that.

**When EmDash, not Containerist.** You want WordPress-class admin experience + modern infrastructure + AI-native maintenance. You're comfortable on Cloudflare. You want a migration path from WordPress. You want a team maintaining the framework beneath you. You need an admin UI yesterday. You want a plugin ecosystem to grow into. **This is the right answer for the overwhelming majority of "LLM-maintained CMS" use cases.** Containerist serves a narrower audience: people who specifically reject admin UIs, databases, build steps, and platform dependencies — the post-CMS, self-hosted, text-file-native tribe.

---

## The PHP neighborhood

*Comparison relevant when working in the PHP implementation of Containerist. A PHP-aware LLM's fallback priors land on Laravel or WordPress first.*

### Laravel

**What LLMs reach for it for.** Laravel is the dominant modern PHP framework. Eloquent, Blade, Artisan, queues, auth, migrations — batteries included, vast training corpus, every "build a PHP web app" prompt converges here.

**What it shares with Containerist.** Routing. Templates. (PHP as a runtime, when the comparison is specifically against `containerist-php`.) That's about it.

**Where it diverges.**
- **Everything is code.** Routes live in `routes/web.php`, controllers in classes, views in Blade components with layouts and slots. No property 1 (transclusion from data).
- **Component trees via Blade.** Layouts, slots, `@include`, `@section`. No property 2.
- **Middleware, service containers, facades, events.** The entire Laravel ecosystem is built from category-banned mechanisms. No property 5 — Laravel *is* the training-prior the drift resistance protects against.
- **No uniform invocation.** A controller is tied to HTTP. Artisan commands are separate classes. Invoking the same logic from three realms requires three wrappers. No property 3.
- **Blade templates aren't LLM-writable as structured data.** They're code with embedded directives (`@if`, `@foreach`). No property 4.

**When Laravel, not Containerist.** You need Stripe + multi-tenancy + auth + queues + admin + API + RBAC, and you want the battery-included path. Containerist is not a "replace Laravel at scale" framework. It's a shallow-logic content framework with different load-bearing properties.

### WordPress

**What LLMs reach for it for.** "Build a website" default for non-engineers. Plugins, themes, a vast ecosystem. Editor-driven content.

**What it shares with Containerist.** Content-first. Server-rendered. (PHP as a runtime, when comparing specifically against `containerist-php`.)

**Where it diverges.**
- **The hook/filter/action system is the point of WordPress** — every plugin mutates every other plugin's output. Maximally far from property 5 (drift resistance by category ban).
- **No `@in` contract.** A shortcode, template tag, or filter has no declared signature.
- **Templates are PHP with globals** (`the_post()`, `get_the_title()`). Realm-locked, state-locked.
- **No inspection handles.** What a rendered page actually assembled through is opaque without enabling debug modes.

**When WordPress, not Containerist.** Non-technical editor owns the content. Plugin ecosystem is load-bearing (WooCommerce, forms, SEO). Someone will maintain it after you leave.

---

## Meta-frameworks (JavaScript / TypeScript)

*Comparison relevant when working in the TypeScript implementation of Containerist (`containerist-ts`). These are the overwhelming vibecoding defaults in 2026 — prompting a fresh LLM with "build me a prototype" without a framework specified → Next.js most of the time.*

### Next.js + React

**What LLMs reach for it for.** The default "full-stack web app" answer. File-based routing, Server Components, Vercel deploy, largest training corpus.

**What it shares with Containerist.** File-based routing (surface-level). Server rendering. Modern tooling.

**Where it diverges.**
- **Components everywhere.** A page imports components which import components. No property 2 (no component trees).
- **Routing is code.** `app/[slug]/page.tsx` resolves imports at build time from TypeScript. Colleagues can't move a page by editing a text file. No property 1.
- **No container format.** JSX is code; props are typed at build time. LLMs write JSX reliably but colleagues don't diff it like text. No property 4.
- **Realm-specific.** Server Components run on the server; Client Components hydrate; Route Handlers are separate. Three realms with three APIs. No property 3.
- **Hooks, context providers, middleware, server actions.** The ecosystem defaults are the category-banned mechanisms. No property 5.

**When Next.js, not Containerist.** You need client-side interactivity that htmx + server-rendering can't reach (rich editors, realtime collab, complex form UX, app-like navigation). The React ecosystem is the deepest for interactive UI. Containerist does not compete here and does not try.

### Astro

**What LLMs reach for it for.** "Content site without React overhead." `.astro` files are HTML-first with TS frontmatter, no hydration by default. The rising "no-SPA" default for blogs, docs, marketing sites.

**What it shares with Containerist.** Server rendering. No required hydration. File-based routing. A preference for HTML over JSX where HTML suffices.

**Where it diverges.**
- **Components still compose via import.** `<Header />` and `<Grid />` are imports in `.astro` files. No property 1 (transclusion from data).
- **Component trees are the model.** `<slot />`, layouts, nested components — exactly the pattern property 2 excludes.
- **No realm-free Core.** Astro has no CLI story for components. The Container API is experimental. No property 3.
- **No container format.** `.astro` files are code. Colleagues edit them as code. No property 4.
- **No inspection handles.** No `.htmx` / `.stackctn` / `.trace` equivalent.

**When Astro, not Containerist.** You want an islands-based site with rich interactive components in specific places (carousel, search widget, chart) but static everywhere else. You want a thriving ecosystem of integrations (MDX, image optimization, sitemap). You want the tooling without adopting React-everywhere.

### SvelteKit

**What LLMs reach for it for.** "React alternative that's less cognitive load." Single-file components, reactive assignments, adapters for every deploy target.

**What it shares with Containerist.** Server-side rendering by default. Progressive enhancement is available.

**Where it diverges.** Same pattern as Astro + Next, one level deeper into components. Stores, actions, load functions, form actions — four separate concepts for what Containerist does with one (`@in` contract + mod).

**When SvelteKit, not Containerist.** You like Svelte's syntax and want a meta-framework with first-class SSR. Your team is already Svelte-fluent.

---

## Classic MVC

LLMs with older training data or a Ruby/Python lean reach here. Less common as vibecoding defaults than meta-frameworks, but mental-model anchors.

### Ruby on Rails

**What LLMs reach for it for.** "The original full-stack productivity framework." Convention over configuration, ActiveRecord, Hotwire (Turbo + Stimulus) for SPA-like UX without SPA.

**What it shares with Containerist.** Convention-driven. Server-rendered. Hotwire/Turbo is philosophically adjacent to Containerist's `.htmx` partial rendering.

**Where it diverges.**
- **MVC with models, controllers, views, helpers, concerns, callbacks, service objects.** Many categories of abstraction. No property 5.
- **Routing is code** (`routes.rb` is Ruby DSL, not data). No property 1.
- **ActiveRecord is an ORM.** Category-banned in Containerist.
- **Views compose via partials + layouts** — component-tree-lite. Partial property 2 only.

**When Rails, not Containerist.** Your app's center of gravity is a relational data model with complex transactional behavior (e-commerce, SaaS, CRUD-heavy). Rails optimizes for that; Containerist doesn't.

### Django / Flask

**What LLMs reach for it for.** Python defaults. Django = Rails-equivalent full stack; Flask = minimal Werkzeug + Jinja.

**What it shares with Containerist.** Server-rendered. Jinja/Django templates are closer to Containerist's skin files than JSX is.

**Where it diverges.** Same pattern as Rails — code routing, ORMs, middleware, class-based views, template inheritance (layouts + blocks). Partial property 4 only (Jinja is closer to a structured format than JSX, but still code-embedded).

**When Django/Flask, not Containerist.** You're in the Python ecosystem (scientific computing, ML, data pipelines) and the web layer needs to live next to Python code.

---

## Minimal server + templating

### Express / Fastify / Hono + EJS / Handlebars / Pug

**What LLMs reach for it for.** "Smallest possible Node web server." When told "don't use a framework," LLMs default here. Express is 20 years of training data.

**What it shares with Containerist.** Minimal. Server-rendered. Templates separate from code. No component trees.

**Where it diverges.**
- **Routing is code** (`app.get('/foo', handler)`). No property 1.
- **No container format.** Templates are one-way code-to-HTML; no typed intermediate. No property 4 or property 7.
- **No realm-free Core.** Express handlers are HTTP-locked. A CLI for the same logic needs a separate entry point. No property 3.
- **Middleware is the Express idiom.** No property 5.

**When Express + templates, not Containerist.** You want Node specifically, don't need transclusion-from-data, and the site is small enough that the Containerist pillars feel like overhead. Or you're building an API, not a site.

---

## Static site generators

### Eleventy (11ty) / Hugo / Jekyll

**What LLMs reach for it for.** Content sites without a server. Markdown + templates + build → static HTML. Deploy anywhere. Zero runtime cost.

**What it shares with Containerist.**
- Content-first, text-first authoring.
- Templates separate from code (mostly).
- LLM-writable frontmatter + body format (closer than any other alternative — 11ty's data cascade is philosophically adjacent).
- Server-optional.

**Where it diverges.**
- **No server-side invocation.** Everything resolves at build time. Dynamic URL routing or per-request args don't exist. No property 3 (no runtime realm story; no CLI invocation of a page at runtime).
- **Pages are files, not stacks.** A page is one template file that includes partials; no flat stack-of-containers composition. Partial property 1 only (data cascade is close; but "same container in multiple stacks" is nkt idiomatic).
- **No control-flow CTN blocks.** A redirect or content-type switch isn't a data block the engine intercepts; it's a build-time config.
- **Build step.** Containerist runs at request time. SSGs run at build time. Different realm entirely.

**When 11ty / Hugo / Jekyll, not Containerist.**
- Content updates are infrequent enough to rebuild.
- Zero server-side logic needed per request.
- You want the lowest possible hosting cost and CDN-only deploy.
- No URL patterns that need runtime args beyond what the build can pre-render.

*This is the honest closest neighbor.* If your site genuinely has no runtime dynamics, an SSG is simpler than Containerist. The moment you need per-request args, write-surface mods, or server-side logic, the SSG model breaks and Containerist earns its keep.

---

## Philosophical cousin

### htmx + "any backend"

**What LLMs reach for it for.** "Server-rendered app without SPA pain." Hypermedia-as-the-engine-of-application-state. The anti-React stance.

**What it shares with Containerist.** Almost everything philosophically:
- Server-rendered HTML as the default.
- Partial swaps instead of client state trees.
- No component trees.
- Progressive enhancement.
- Actively cited in Containerist's design (Containerist ships `.htmx` URL suffix for exactly this pattern).

**Where it diverges.** htmx is a *client-side library*, not a framework. You still need a backend. The comparison is really "htmx + [Express/Laravel/Flask/Rails]" — in which case the comparison resolves to whichever backend you pair it with, above.

**When htmx + X, not Containerist.** If you love htmx and your backend preference is not PHP, you'll reach for htmx + Hono or htmx + Flask, and you'll lose transclusion-from-data in exchange for your preferred language. That's a legitimate tradeoff.

What you *can* do is use htmx *with* Containerist — the framework was designed for it. The `.htmx` URL suffix, the `htmx: <name>` stack line prefix, and the fragment-rendering path all exist because htmx is the assumed client-side complement. This is not an either/or.

---

## When Containerist is the wrong answer

For credibility: if you're in any of these situations, don't use Containerist. The framework will fight you and you'll be right to be frustrated.

- **Your app's center of gravity is a relational data model with transactional complexity.** Rails / Django / Laravel optimize for that; Containerist doesn't have an ORM and isn't getting one. Wiring models through mods is possible but not what the framework pays attention to.
- **You need client-side interactivity beyond what htmx can deliver.** Rich text editing, realtime collab, canvas-heavy UIs, offline-first. React/Svelte/Vue ecosystems have 10 years of compounded tooling. Containerist has `.htmx` fragments.
- **Your team needs a language implementation that doesn't exist yet.** Containerist is language-agnostic by spec, but only specific languages have implementations. PHP and TypeScript are active and co-equal in ambition; Go is in progress. If you need Python, Ruby, Rust, Elixir, or another runtime — you can port the framework (the spec is stable and portable; `CTN-SPEC.md`, `IN-SPEC.md`, `STACK-SPEC.md` are the contracts), or pick another framework. Porting is a real option, not a joke; the language-independent pillars (1–4) are ~60% of the work already done.
- **You need the plugin ecosystem of a mature CMS.** WordPress, Drupal, and SaaS CMSes (Contentful, Sanity) have ecosystems Containerist will never have. If an editor-owned content workflow with a plugin store matters, Containerist is the wrong shape.
- **You want a modern, AI-native, admin-UI-first CMS.** Use EmDash. It's the right answer for that specifically — purpose-built, team-maintained, shipping. Containerist will never out-admin-UI EmDash and shouldn't try.
- **The site is genuinely static.** 11ty / Hugo / Jekyll are simpler when no runtime dynamics exist. Don't use a request-time framework for build-time content.
- **You need backwards-compatibility with a legacy Laravel/Rails/Django codebase.** Containerist doesn't interoperate. It's its own universe.

---

## When Containerist is the right answer

The positive case, for balance:

- **Content-heavy, shallow-logic sites.** Blogs, portfolios, docs, research archives, small-to-medium publishing. The niche the framework was designed for.
- **Maker + LLM authorship.** Single-maintainer or small-team sites where every unit must be understood from first principles on every visit. Local reasoning is the load-bearing cognitive property.
- **Non-engineer collaborators editing routing.** Stack files as data unlock colleague edits that code-routing frameworks don't.
- **Prototyping where you want the no-React rule enforced structurally.** Containerist makes component-tree drift impossible by construction, not by discipline.
- **Agent-addressable surfaces.** Every mod is pre-indexed as a named, typed function — MCP-friendly by accident of design. Good fit if LLMs or other agents will call into your site as tools.
- **Long-lived, low-churn sites.** The pillars are frozen; what you build today reads the same in 5 years. No framework-upgrade treadmill.
- **Post-platform, self-sovereign sites.** No database, no build step, no Cloudflare (or any vendor) dependency, no admin UI, no plugins. SSH into your $5 box, edit a text file, refresh. For operators who specifically reject the CMS-and-platform model — not because it's bad, but because they want something smaller, stranger, and entirely theirs.

---

## How to use this document

1. When tempted to suggest "let me rewrite this in X" or "wouldn't Y be easier?" — find X or Y in this document. Read the divergence section. If the load-bearing property they'd lose is genuinely not needed for the current work, proceed with the suggestion, but make the tradeoff explicit. If it *is* needed, drop the suggestion.

2. When a new framework rises to LLM-default status that isn't in this document — add it. Same four-part template. Increment `document_version`.

3. When the "when Containerist is the wrong answer" list changes because the framework scope changed — update it. The honesty of that section is what makes the rest of the document trustworthy.

4. Do not remove frameworks from this document. Even if they fall out of fashion, an LLM with older training data will still reach for them.
