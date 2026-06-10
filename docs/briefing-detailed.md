# Containerist — Detailed Briefing

*Long-form onboarding document. Read end-to-end when first encountering a Containerist codebase, or when a pillar's rationale is unclear. For the pastable operator summary, see `docs/containerist.md`. For in-flight intents and proposed changes, see `direction.md`. For the current implementation plan, see `plan.md`. For external critiques and the framework's betting hypotheses, see `under-review.md`.*

---

## 0. Orientation

### What this document is

A briefing written by one LLM instance for the next — and for the human who commissioned both. It names what Containerist is, what it is not, what rules it runs by, and why. Each rule carries its rationale so a future reader can judge edge cases rather than follow blindly.

### Who it's for

- **The next LLM instance** working on Containerist or any fork (Konnexus, Pond, etc.). You are stateless. Your context window is finite. This document is designed to give you enough to work usefully in one pass, without re-deriving the architecture from source.
- **The author** (or any human maintainer), as a reference for the design's intent — especially when reviewing an LLM's proposed changes.

### How to use it

Read it end-to-end on first encounter. The pillars reference each other; cherry-picking will mislead.

After the first read, use the operator briefing (`docs/containerist.md`) as the task-time reference. Return here when a pillar's rationale is unclear, when a proposed change feels like it might cross a line, or when you're tempted to "improve" something and want to check whether the improvement is actually drift.

---

## 1. What Containerist Is

Containerist is a modular PHP framework for building websites from small, composable units. It has three storage concepts and one render step:

- **Mods** — PHP files that transform inputs into typed output (CTN blocks). Stored in `modules/**/*.ctn.php` (or `.php`).
- **Static containers** — Plain-text files containing CTN blocks. Stored in `containers/**/*.txt`.
- **Stacks** — Flat lists of container names that compose pages. Stored in `stacks/*.txt`.
- **Skins** — `.html` + `.css` pairs that render each CTN type. Stored in `skin/`.

A web request flows through a single entry point (`index.php`), which resolves the URL to a stack, invokes each mod/static listed by that stack in order, concatenates their CTN output, runs the combined output through the skin renderer, and returns HTML.

The same mods can be invoked from the command line (`./ctnr <mod> arg=val`) or from other PHP scripts (`$C->{mod}(['arg' => 'val'])`), producing the same CTN output. That realm-independence is not a feature added on top — it is structural.

### What makes it different

Most PHP frameworks couple their parts tightly to one realm (typically HTTP). A Laravel controller cannot be cleanly invoked from the command line. A Rails partial cannot be consumed as data by another partial. A Symfony twig template cannot be tested in isolation from the request object.

Containerist's mods can. Because:

- Every mod declares its inputs and returns typed CTN output.
- The output type is fixed (CTN blocks), so mods compose by string concatenation.
- The realm (HTTP, CLI, PHP-call) is a thin adapter on top of a realm-free Core.
- The render layer is `foreach block: look up skin[type]; substitute; concatenate`.

That's the whole architecture. The Core is small enough to read end-to-end (a few hundred lines that register mods, execute them under an `@in` schema, and parse CTN); each adapter is a thin shim on top.

---

## 2. What Containerist Is Not

Naming what the system is not is as important as naming what it is. Without these boundaries, the next LLM (or a tired author) will drift toward them by default.

- **Not a framework in the Laravel / Symfony / Rails sense.** There is no autoloader, no service container, no dependency injection, no ORM, no middleware pipeline, no event bus, no form builder.
- **Not a static site generator.** The system runs live on every request. Mods execute dynamically. Caching is not part of the architecture (though an adapter could add it).
- **Not a component system.** CTN blocks are not components. They do not nest. There is no props / slots / children system. The block format is a data shape; the skin is a rendering function.
- **Not an MVC framework.** There are no controllers, no views, no models in the conventional sense. A mod is a function. A stack is a route. A skin template is a pure-presentation layer. The classical M/V/C partitioning does not apply and should not be imposed.
- **Not a templating engine.** Mustache is used inside skin templates, but it is an implementation detail. The render contract is "CTN type → html+css pair." How the html is produced from the block fields is a local choice per template.
- **Not designed for teams of 20 developers.** The simplicity constraints make sense for a small codebase maintained primarily by LLMs and a single author (or very small team). Forcing Containerist's patterns onto a large team codebase would be damage.

If you find yourself writing code that assumes any of the above, you are outside the design envelope. Stop and re-read this document.

---

## 3. Lineage

Containerist has existed in several iterations. Understanding the lineage helps distinguish the stable Core from the incidental.

### Original Containerist (Konnexus, circa 2018+)

The first implementation. Production-tested by running Konnexus.net. Its defining traits:

- Base class entangled with Request class in one ~480-line `index.php`.
- Runtime magic (`__call`, `extract($arguments)`, recursive wildcard pattern generation in `find_stack`).
- Multiple parallel registries (`mods`, `container_mods`, `static_containers`, `stacks`).
- Works, has worked for years, has a real briefing (`containerist.readme.txt`) written by Cursor for future Cursor sessions.

The original is the thing this document defends the *intent* of. The intent was right; the expression had drifted.

### Pond-mk3 (the fork / refactor)

A second iteration. Its defining traits:

- `containerist.php` as pure Core: ~80 lines, no request/session/output logic. Just mod registration + execution.
- `cli.php` as a minimal shim that returns `$C` for shell scripts.
- `ctnr` as a bash wrapper that turns `./ctnr <mod> key=val` into `$C->ctn('<mod>', ['key' => 'val'])`.
- `index.php` as the web adapter (entry) (thinner, but still inherits some complexity from the original).

Mk3 is what the architecture looks like when the Core gets separated from the realm. It is the direction future reworks should extend.

### What carries forward

Across all iterations:

- The CTN block format.
- The flat-stack composition model.
- The namespace-flat container / mod unification.
- The one-html-one-css-per-type skin contract.
- The URL-as-variable-scope routing model.

### What should be shed

Specifically:

- `extract($arguments)` as the implicit input mechanism. Replace with declared-inputs contract.
- `$C->__call` for mod invocation. Replace with explicit method or a single `$C->ctn('name', $args)` call.
- Mod-specific heuristics in `ctnr`. Replace with generic key=value resolution, driven by the mod's own declared inputs.
- Duplicated `.html` / `.htmx` branches in the web realm router.
- Parallel registries where one would suffice.

The rework agenda, in short: keep the design. Replace the magic with declaration. That's it.

---

## 4. Mission — The Four Simplicities

The design goal, stated by the author:

> Simple parts. Simple interfaces and interaction between parts. Simple to test individually. Simple to change in isolation. Together, they can accomplish complex stuff.

This is Unix philosophy in the McIlroy tradition — "write programs that do one thing and do it well; write programs to work together" — applied to a web context. The insight that makes it hold up:

**Complexity belongs in composition, not in parts.**

Each mod is small. Each stack file is flat. Each skin template is one CTN type. Every rule below is a *restraint* that keeps one of the parts simple. When you compose them — URL → stack resolution → bound variables → four mods → each modifying its input → CTN output → skinned through template pairs — you get an article page, a feed, a digest, an archive. Same four simplicities, combined differently.

The four properties are not independent. They reinforce each other:

- **Simple parts** make simple interfaces possible. A part with one job has a small surface.
- **Simple interfaces** make isolated testing possible. If the contract is small and uniform, you can feed a part its inputs and check its outputs without wiring up the rest of the system.
- **Isolated testing** makes isolated change possible. If you can verify one part alone, you can modify it with confidence.
- **Isolated change** keeps the system small. Without it, every change touches multiple parts, and the pressure to "factor out" common concerns creates abstractions that erode the other three.

Break any one of the four, and the other three degrade. That's why the criteria and the pillars exist — to defend all four simultaneously.

---

## 5. Review Criteria (Expanded)

Five questions to ask of any proposed change. Each must pass; if one fails, the change needs justification explicit enough that a future instance can evaluate it.

### Criterion 1 — Does this make a part more complex?

**What it measures:** The cost of reading and understanding an individual part (mod, static container, skin template, stack file) after the change.

**Passes when:** The change keeps the part at or below its current complexity. New functionality is added by creating new parts, not by enlarging existing ones.

**Fails when:** A mod grows a second responsibility. A template acquires branching logic. A stack file gains conditional inclusions. A helper function starts doing two things.

**Typical LLM drift:** "Let me add a parameter to this mod so it can handle the edge case too." The edge case usually wants its own mod.

**Example (fail):**
```php
// modules/posts/post-render.ctn.php
// @in: id, include_comments (optional), show_author (optional),
//      comment_sort (optional), admin_preview (optional)
```
Five params means five jobs. Split into `post-render`, `post-comments`, `post-admin-preview`, compose via stack or mod-internal calls.

**Example (pass):**
```php
// modules/posts/post-render.ctn.php
// @in: id
```
One job, one input. If you need the admin-preview variant, it is a different mod.

### Criterion 2 — Does this make an interface less uniform?

**What it measures:** Consistency of the contract across the system. Every mod takes declared `@in` args, returns output of its declared type (`.ctn.php` → CTN, `.html.php` → HTML, etc.). Every stack is a flat list. Every realm invokes mods the same way.

**Passes when:** The change preserves the uniform shape — mods still take declared args and return output matching their filename-declared type, stacks remain flat, the realm invocation signature does not diverge.

**Fails when:** A mod returns a type its filename doesn't declare (e.g., a `.ctn.php` file returning HTML directly, instead of wrapping it in a `CTN: standard` block or renaming to `.html.php`). A stack file grows a conditional include syntax. A skin template expects fields not in the CTN block. The renderer has to special-case certain mods.

**Typical LLM drift:** "This mod really needs to return JSON for the API." Two paths are in-bounds: (a) return a CTN `data` block whose body is the JSON payload; the api adapter extracts and serves it — pillar-aligned; (b) name the file `foo.json.php` so the filename declares the type — also pillar-aligned. What isn't OK: a `.ctn.php` mod silently returning raw JSON.

**Example (fail):**
```php
// inside a mod body
header('Content-Type: application/json');
echo json_encode($data);
exit;
```
Realm-specific side effects inside a mod. Breaks uniformity. Breaks realm-independence.

**Example (pass):**
```php
// inside a mod body
echo "CTN: data\n";
echo "payload: " . yaml_dump($data) . "\n";
echo "---\n";
```
Uniform output. The renderer can render a `data` block as JSON if asked (e.g. via `.json` suffix). The mod doesn't care.

### Criterion 3 — Does this require a specific realm to test?

**What it measures:** Whether a mod can be exercised from any realm (CLI, HTTP, PHP-call) with the same inputs producing the same outputs. The tester (LLM or human) chooses the cheapest realm for the task, usually CLI.

**Passes when:** The mod produces identical output regardless of realm. A CLI invocation (`./ctnr mod-x arg=val`) works. An HTTP invocation (`curl {domain}/mod-x.ctn?arg=val`) works. A PHP call (`$C->ctn('mod-x', ['arg' => 'val'])`) works. All three return the same CTN string.

**Fails when:** The mod reads `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`, or `STDIN` directly. The mod calls `session_start()`, emits HTTP headers, or writes to stdout for terminal formatting.

**Typical LLM drift:** "I'll just read `$_GET['id']` here — it's convenient." It is convenient for thirty seconds. Then the mod is web-locked forever, and every test needs a running server.

**Justified exceptions:** Thin edge-layer mods that are *specifically* about a realm's I/O — a multipart-upload receiver, a CLI cron orchestrator, a session-establishing auth endpoint. These are *thin*: they parse the realm-specific input, then hand off to a realm-agnostic mod for the actual work.

**Example (fail):**
```php
// modules/posts/post-save.ctn.php
$title = $_POST['title'];
$body = $_POST['body'];
file_put_contents("posts/$id.txt", "...");
echo "CTN: confirmation\n...\n";
```
Web-locked. Not testable from CLI without faking `$_POST`.

**Example (pass):**
```php
// modules/posts/post-save.ctn.php
// @in: id, title, body
file_put_contents("posts/$id.txt", "title: $title\nbody: $body\n");
echo "CTN: confirmation\n---\nSaved.\n";
```
Realm-agnostic. The thin web-form handler mod (if it exists) reads `$_POST` and invokes `$C->post_save([...])`. Both are simple. The save logic is testable from CLI.

### Criterion 4 — Does this require changing more than one file to adjust one behavior?

**What it measures:** Cohesion. Related behavior lives in one place; changing one thing means opening one file.

**Passes when:** Adding, removing, or modifying a single behavior — adding a field to an article, changing how comments render, swapping a data source — touches one mod, or one stack, or one skin template.

**Fails when:** Adding a field requires editing a mod, its template, a helper in a lib directory, a stack, and the briefing. Changing a rendering rule requires hunting through three files.

**Typical LLM drift:** Abstracting too early. "Let me extract this common pattern into a base class / shared helper / utility trait." The common pattern is usually three similar lines, which are cheaper to duplicate than to abstract.

**Example (fail):** Adding a `reading_time` field to articles requires:
- Edit the mod that reads the article file.
- Edit a shared `ArticleFormatter` class.
- Edit a Mustache partial.
- Edit a helper in `lib/text/`.
- Edit the stack (maybe).

Four to five files for one field. Abstractions are doing harm.

**Example (pass):** Adding `reading_time`:
- Edit `modules/posts/post-render.ctn.php` (compute it, include it in the CTN block).
- Edit `skin/post.html` (add `{{reading_time}}` where you want it displayed).

Two files, both obvious. No shared helpers to navigate.

### Criterion 5 — Does this degrade the experience on a constrained device?

**What it measures:** Whether the site stays usable on an old low-resource device (benchmark: a 2005 PowerBook G4) with a slow network and limited or no JS. Server-render, no fat client, minimal CSS. HTML is the product.

**Passes when:** The page renders meaningfully without JavaScript, loads under a slow connection in reasonable time, and works on a constrained screen/CPU. JS is an enhancement layer over working HTML, not a requirement for the page to function.

**Fails when:** The default rendering path requires a JS framework to produce any HTML. The initial payload is multiple megabytes. A core reading flow depends on a hydration step.

**Typical LLM drift:** "Just ship this as React/Alpine/Vue — the user has JS." Probably, but the constraint exists precisely to counter this reflex. The G4 criterion is an anti-drift vaccine: every time you reach for a heavy client, ask whether the server-rendered version is simpler. Usually it is.

**Justified exceptions:** Interactive widgets that are *enhancements* on top of working content (HTMX-driven updates, a sortable table, a live-search box). The enhancement may depend on JS; the underlying content must not.

**Example (fail):** A note page that fetches its body via `fetch('/n/K250624A.json')` and inserts it into an empty `<article>` on client-side load. Without JS, the page is empty.

**Example (pass):** A note page that server-renders the full body into HTML on first request. JS optionally enhances (HTMX swaps related-notes without a page reload), but the body itself is already there on first render.

### Using the criteria

Before writing a line of code, ask the five questions. State the answers, even briefly, in a commit message or chat turn. If all five pass cleanly, proceed. If one fails with a good reason, name the reason. If one fails without a reason you can articulate, that is drift — stop and reconsider.

LLMs will want to fail these criteria constantly. That is not the LLM's fault. Training data is overwhelmingly framework-heavy, abstraction-heavy, design-pattern-heavy. The criteria exist precisely to counter that pull.

---

## 6. The Eight Pillars

The eight pillars split into two groups of four. Pillars 1–4 define the **format** — the CTN spec, language-independent, portable across implementations. Pillars 5–8 define the **processing** — the runtime that implements the format. A future port of Containerist to another language preserves the first four as-is and re-implements the second four.

Within each group, pillars read fine-grained to coarse: the atom first, then how atoms compose, then how they reach inputs and outputs.

Each pillar below has five parts:

- **Statement** — The rule, in one sentence.
- **Rationale** — Why it exists.
- **Current realization** — How the existing code implements it.
- **LLM drift risk** — What a future instance will try to "improve" if the pillar isn't named.
- **Example** — A minimal illustration of the pillar in action.

---

### Pillar 1 — CTN parser

**Statement:** Every piece of rendered content is one or more CTN blocks. A CTN block has a declared type, YAML frontmatter, and optional Markdown body. A file without an explicit `CTN: <type>` header defaults to a single `standard` block whose body is the file's contents. The implicit default is a feature.

**Rationale:** CTN is the pivot type of the entire system. Mods produce it, stacks compose it, skins render it, realms transport it. Its stability is load-bearing. The implicit-standard default means plain text files are valid containers without ceremony, which keeps the authoring cost of a static container at zero.

**Current realization:**

```
CTN: article
title: Example post
date: 2026-04-17
tags: [briefing, containerist]
---

# Example

Markdown body here. Can span multiple lines, multiple paragraphs.

CTN: related
refs: [another-post, yet-another]
---
```

Two blocks in one container output. Each block: a type tag, YAML fields between the `CTN:` line and the `---` separator, optional Markdown body after.

A plain text file:
```
Just some text, no ceremony.

This is still valid — it is a `standard` block with this content as body.
```

**LLM drift risk:** Two drifts to guard against.

1. "Let me normalize all static containers to have explicit `CTN: standard` headers for consistency." This destroys the zero-ceremony default. Reject.
2. "Let me allow CTN blocks to contain other CTN blocks for layout purposes." This destroys the flat-block invariant that makes skins a `foreach`. Reject.

**Example (preserving the implicit default):**

`containers/static/footer.txt`:
```
© Konstantin Weiss, since 2005. Licensed CC-BY-SA.
```

No header. Renders as a `standard` block. Correct.

`containers/static/footer.txt` after bad LLM intervention:
```
CTN: standard
---
© Konstantin Weiss, since 2005. Licensed CC-BY-SA.
```

Header added "for consistency." Wrong. Revert.

### Pillar 2 — Pages are stacks of independent containers

**Statement:** A page is assembled by listing containers in a stack file. The list is flat. There is no conditional inclusion, no computed membership, no nesting at the stack level.

**Rationale:** The stack file is the page's *map*. A reader should be able to open the stack file and know the page's full structure without simulating logic. If stacks contained conditionals or computed inclusions, the map would be incomplete and a reader would have to trace code paths to know what renders. That is exactly the failure mode pillar 2 (entry = map) in the underlying manifesto warns against.

**Current realization:**

```
# stacks/n--*.txt
CTN: stack
---
note-header
note-title
note-body
note-tags
note-related
footer
```

Seven containers, in order. No `if`, no `include`, no loops, no wildcards (except the stack *name* itself, which is a URL pattern, not a content mechanism).

**LLM drift risk:**

- "Let me add a conditional so we only render `note-related` if there are related notes." No — `note-related` itself should emit empty (or a "no related posts" message) when there aren't any. The decision lives inside the mod, not at the stack level.
- "Let me make the stack dynamic by generating the list from a database." No — a stack is a file. A mod inside the stack can loop internally, but the stack-level composition stays flat.

**Example (pass):**

A stack lists a mod called `note-related`. That mod internally does:
```php
$related = find_related($note_id);
if (empty($related)) {
  echo "CTN: empty\n---\n"; // or no output
} else {
  foreach ($related as $r) {
    echo "CTN: related\n...\n---\n";
  }
}
```

The emptiness decision is encapsulated in the mod. The stack just says "put `note-related` here."

**Example (fail):**

A stack file grows:
```
CTN: stack
---
note-header
note-title
note-body
@if $note->has_tags
  note-tags
@endif
note-related
```

Conditional at the stack level. The stack is no longer a map; it is a template with logic. Reject.

### Pillar 3 — Container output is a flat list of typed blocks

**Statement:** A container's output is zero or more CTN blocks at one level. Blocks do not nest. Rendering is a `foreach` over blocks; each block's type determines the render path. In the web renderer, that render path is realized as a `skin/{type}.html` template + `skin/{type}.css` stylesheet pair. Other renderers realize it differently (JSON serialization, ANSI text, etc.). Adding a new visual element in the web renderer means adding a new type with its pair of files — never adding a layout engine or a nesting mechanism.

**Rationale:** Flat output makes rendering a fold (pure, order-preserving, no branching). One-type-one-pair makes the skin directory a complete catalog — `ls skin/*.html` enumerates every possible visual element. No component tree means no recursion, no slot-resolution logic, no layout algebra. The cost of rendering is linear in the number of blocks; the cost of understanding the renderer is constant.

**Current realization:**

```
skin/
  standard.html    standard.css
  teaser.html      teaser.css
  article.html     article.css
  related.html     related.css
  stack.html       stack.css
  ...
```

Each `.html` is a Mustache template that substitutes fields from the block's frontmatter and body. Each `.css` is scoped to that type. The render step:

```php
foreach ($blocks as $block) {
  $tpl = file_get_contents("skin/{$block->type}.html");
  $output .= mustache_render($tpl, $block->fields);
}
```

That's it.

**LLM drift risk:**

- "Let me add a `layout` field to CTN blocks so you can wrap blocks in a frame." This is the component-tree slippery slope. A wrapper block should be a separate CTN block of type `frame-open` + your content blocks + `frame-close`, or the wrapping should happen at the skin layer for a specific type, not as a general mechanism. Reject nesting.
- "Let me allow blocks to reference other blocks by ID." Same problem. Reject.
- "Let me introduce a shared `partials/` directory for skin templates." Mustache partials are harmless in small doses, but each partial is a departure from "one type → one pair." Resist.

**Example (pass):**

A new visual element — a callout box — is introduced:
```
skin/callout.html
skin/callout.css
```

A mod emits `CTN: callout\nlevel: warning\n---\n...`. The render loop picks up the new type automatically. No code changes in the renderer.

**Example (fail):**

A mod emits:
```
CTN: layout
frame: sidebar
blocks:
  - teaser
  - related
---
```

Blocks nested inside another block. The renderer now needs recursion, layout resolution, slot filling. Reject.

### Pillar 4 — The URL is the variable scope of the stack

**Statement:** URL parts serve two roles simultaneously: they select a stack (via priority-sorted wildcard matching — fewest wildcards win, so `foo--bar` beats `foo--*` beats `*--bar`) and they bind as named variables to every mod the stack lists. The binding is declared in the stack's `@args` frontmatter: `@args: note_id = {{2}}` binds URL part 2 to the name `note_id`. Literals are also supported: `greeting = "Hello"`. Mods declare their inputs via `@in:`; the adapter populates those inputs from the resolved bindings + any remaining URL parts as numeric keys (addressable as `{{1}}`, `{{2}}`, … in stack-side grammar). Mods never know which stack called them, which URL produced the request, or which realm invoked the call.

**Rationale:** This is the framework's routing model. It is elegant — one mechanism does routing, parameter extraction, and scope propagation — and it is the hardest pillar for an LLM to maintain, because the binding is currently implicit in the original Containerist (mods read magic variables populated by `extract($arguments)` from the Request object). The rework moves this from implicit to declared.

**Current realization (with the rework's declared-inputs pattern):**

Stack file:
```
# stacks/n--*.txt
CTN: stack
args:
  note_id: {{2}}
---
note-header
note-title
note-body
note-tags
note-related
```

Mod file:
```php
// modules/pond/note-title.ctn.php
// @in: note_id (required)
$note = load_note($note_id);
echo "CTN: title\n---\n# {$note['title']}\n";
```

Request trace for `/n/K250624A`:
1. Web realm receives URL, parses parts: `[n, K250624A]`.
2. Stack resolver matches `n--*` pattern (the `--` is the parts separator in stack names, `*` matches any single part).
3. Stack frontmatter declares `args.note_id = {{2}}` — meaning: bind URL part 2 to the variable `note_id` in the args map.
4. For each listed mod, invoke `$C->ctn(name, ['note_id' => 'K250624A'])`.
5. Each mod reads `note_id` via its declared `@in`. Mods that don't declare `note_id` ignore it.
6. Mods return CTN; outputs concatenate.
7. Skin renders.

**LLM drift risk:**

- Mods that read `$_GET['id']` directly. Breaks pillar 6 (realm-independence) and couples the mod to a specific URL shape.
- Mods that inspect which stack called them. Breaks pillar 7 (namespace-flat) and pillar 5 (mod doesn't know its caller).
- Stack files that compute args dynamically ("if URL part 2 starts with `k`, bind to `note_id`, else `tag_id`"). The URL-to-args mapping should be a static declaration. Branching lives inside mods, not in the stack.
- Mods that "pass state" to other mods via `$GLOBALS` or a session. Mods compose via return values (CTN strings) passed as arguments on the next call, never through side channels.

**Example (pass):**

```
# stacks/tag--*.txt
CTN: stack
args:
  tag_slug: {{2}}
---
tag-header
tag-article-list
footer
```

```php
// modules/pond/tag-article-list.ctn.php
// @in: tag_slug (required)
$articles = find_articles_by_tag($tag_slug);
foreach ($articles as $a) {
  echo "CTN: teaser\n...\n";
}
```

`/tag/cyberpunk` → stack `tag--*` → mods receive `tag_slug = 'cyberpunk'`.

**Example (fail):**

```php
// modules/pond/tag-article-list.ctn.php
$tag = $_GET['slug'];  // web-locked, doesn't match the URL scheme anyway
$articles = find_articles_by_tag($tag);
```

Two failures: web-lock (pillar 6) and inconsistency with the URL binding (pillar 4). An LLM who added this was trying to "fix" something by reading the querystring directly — the fix was to trust the binding.

---

### Pillar 5 — A mod is a typed modifier

**Statement:** A mod is a function that takes declared arguments (via `@in:` comment header) and returns typed output. The output type is declared by the filename suffix: `.ctn.php` produces CTN (the composable default — downstream mods can consume it); `.html.php` produces HTML (terminal; a boundary mod, not further composable); future `.xml.php` / `.json.php` / `.rss.php` / etc. likewise. A mod *transforms* inputs (arguments, context, content sources, other mods' output) into typed output. It does not generate from nothing. The word "mod" means *modifier* — not *module*. Static containers are the degenerate `.ctn.php` case: stored output, zero transformation, empty input.

**Rationale:** Framing mods as *modifiers* rather than *generators* disciplines the mental model. A generator invites invention ("make up something plausible"). A modifier invites sourcing ("what am I transforming, where does it come from?"). The latter is the disciplined frame. Every Unix filter — `grep`, `sort`, `awk`, `tr` — is a modifier; composition comes from filters, not generators.

**Current realization:**

A mod invocation:
```php
$output = $C->ctn('article-teaser', ['id' => 'K260101A']);
```

Inside `article-teaser.ctn.php`:
```php
// @in: id (required)
$article = load_article($id);    // sourcing
$excerpt = first_paragraph($article['body']);  // modifying
echo "CTN: teaser\n";
echo "title: {$article['title']}\n";
echo "date: {$article['date']}\n";
echo "---\n";
echo $excerpt . "\n";
```

The mod sourced the article (from the filesystem), transformed it (extracted the first paragraph, reformatted), and emitted CTN. No invention; all transformation.

**LLM drift risk:**

- Mods that invent content ("generate a placeholder greeting"). If a mod has nothing to modify, it is either a stored constant (make it a static container) or it shouldn't exist.
- Mods that emit something other than CTN (HTML, JSON, redirects, bytes). Uniformity broken.
- Mods that mutate state as a side effect. A mod may read the filesystem or write to it (e.g. a save mod), but its *output* is always CTN describing what happened, not silence or an HTTP redirect.

**Example (pass) — a save mod:**

```php
// modules/posts/post-save.ctn.php
// @in: id (required), title (required), body (required)
$path = "posts/{$id}.txt";
file_put_contents($path, "title: {$title}\nbody: {$body}\n");
echo "CTN: confirmation\n";
echo "saved_id: {$id}\n";
echo "---\n";
echo "Saved.\n";
```

Side effect (file write) is the point of the mod, but the *output* is still CTN. The adapter (web) can inspect the confirmation block and issue a redirect. The mod does not.

**Example (fail) — a generator mod:**

```php
// modules/core/random-quote.ctn.php
$quotes = ['...', '...', '...']; // hardcoded list
echo "CTN: quote\n---\n" . $quotes[array_rand($quotes)] . "\n";
```

This looks like a modifier but is actually a generator with randomness. It is harmless, but if the same logic could be a static container (with the list externalized to `containers/data/quotes.txt` and a second mod sourcing from there), that is closer to the discipline.

### Pillar 6 — Mods are URL/CLI/PHP-addressable with uniform invocation

**Statement:** The same mod produces the same CTN output for the same inputs regardless of invocation realm. The mod does not know, and does not care, which realm called it.

**Rationale:** Uniform invocation is what makes mods composable across process boundaries, testable at the cheapest realm, and reusable in contexts that didn't exist when the mod was written. A mod written for a web page becomes a cron job, a CLI utility, an API response, a test fixture, without modification.

**Current realization:**

Given a mod at `modules/pond/drop-teaser.ctn.php` that declares it needs `drop_id`, all three of these produce identical CTN output:

```bash
./ctnr drop-teaser drop_id=250204a-test
```
```bash
curl 'https://example.com/drop-teaser.ctn?drop_id=250204a-test'
```
```php
$C->drop_teaser(['drop_id' => '250204a-test']);
```

The first two are adapters that build the same args array and call `$C->ctn('drop-teaser', $args)`. The third calls it directly. All converge on the same execution path.

**Always use `$C->ctn('name', $args)`** — not a `$C->name(...)` shortcut. An earlier revision of Containerist exposed `__call` as prototyping ergonomics, but the legibility cost (a stateless reader of `$C->drop_teaser(...)` cannot tell if it's a method, a mod, a static container, or a typo) outweighed the ~8 characters per call. Removed 2026-04-19. The explicit form is the only form. (5.1 further collapsed `$C->mod()` and `$C->ctn()` into a single method — `$C->ctn()` now owns mod invocation, static-container resolution, and bare-stem fallback under one always-CTN-string return contract; see `containerist.md` §"Public Core API".)

**LLM drift risk:** A mod that reaches for realm-specific state inside its body (`$_GET`, `$_POST`, `STDIN`, terminal color codes, `session_start`, HTTP headers). Each such reach locks the mod to one realm.

**Example (pass):**

```php
// modules/pond/drop-teaser.ctn.php
// @in: drop_id (required)
$drop = load_drop($drop_id);
echo "CTN: teaser\n";
echo "title: {$drop['title']}\n";
echo "date: {$drop['date']}\n";
echo "---\n";
echo substr($drop['body'], 0, 200) . "...\n";
```

Works in any realm. Tester picks.

**Example (fail):**

```php
// modules/pond/drop-teaser.ctn.php
$drop_id = $_GET['drop_id'];   // web-locked
session_start();                // web-locked
if ($_SESSION['admin']) { ... } // web-locked
```

Three web-locks in three lines. Untestable from CLI without a mocking harness.

### Pillar 7 — Containers are namespace-flat

**Statement:** A mod (`.ctn.php` in `modules/`) and a static container (`.txt` in `containers/`) are two storage forms of the same concept. Callers use the container's name; the dispatcher resolves to a mod first, then falls back to a static container. Shadowing is permitted: a same-named mod replaces a same-named static.

**Rationale:** Uniform addressing. A stack file listing `footer` doesn't need to know whether `footer` is static or dynamic. The container can be promoted from static to mod (or demoted) without touching any caller. This is substitutability — the same property that makes `ls` work the same whether it's an alias, a shell function, or `/bin/ls`.

**Current realization:**

```
modules/core/footer.ctn.php   →  footer (dynamic)
containers/static/footer.txt  →  footer (static)
```

If both exist, the mod wins. Stack files, other mods, and HTTP callers all write just `footer`.

**LLM drift risk:**

- "Let me add a prefix scheme: `mod_footer` vs `static_footer` for clarity." That destroys substitutability. Reject.
- "Let me separate the URL spaces: `/mod/footer.ctn` vs `/static/footer.ctn`." Same problem. Reject.
- "Let me error out on shadowing to prevent conflicts." Shadowing is intentional — it is how you override a static by introducing a mod. A warning or log is fine; an error is wrong.

**Example (pass):**

Starting with `containers/static/footer.txt`:
```
© Konstantin Weiss
```

Later, you want the footer to include the current year dynamically. Create `modules/core/footer.ctn.php`:
```php
$year = date('Y');
echo "© Konstantin Weiss, 2005–{$year}\n";
```

No callers change. The static is shadowed. Promotion complete.

**Example (fail):**

```
modules/core/_footer_mod.ctn.php  →  _footer_mod
containers/static/footer.txt       →  footer
```

Now callers have to know which is which. Substitutability is broken.

### Pillar 8 — The Core is realm-free — enforced by construction

**Statement:** The Core (`containerist.php` in mk3-style forks) registers mods and executes them. It has no code that talks to a realm (no `$_GET`/`$_POST`/`header()`/`session_start()` calls anywhere in its source), so realm-locking is impossible, not just discouraged. The Core is realm-free and format-free; realm-free **renderers** turn CTN into output (run the acts, format html/raw/source/text); thin per-realm **adapters** do realm I/O (web, cli; future mcp/sse). Adapters and renderers reach the Core only through the public API: `$C->ctn($id, $args)` / `$C->parse_ctn($string)`. Nothing reaches into Core internals (no direct `$C->mods[]` reads). Together, Core + renderers + adapters = Containerist.

**Rationale:** Realm-independence is the property that makes mods testable from any realm, composable across processes, and survivable across rewrites. If the Core is coupled to one realm, every mod inherits that coupling. Pond-mk3 separated the two; that separation is the architectural upgrade over the original Containerist.

**Current realization (mk3):**

```php
// containerist.php — ~80 lines, no $_GET, no sessions, no headers
class Containerist {
  public $mods = [];
  function __construct() { $this->register_mods(); }
  public function ctn($id, $args) {
    // 5.1+: single invocation method. Resolves mod → exact container id → bare-stem.
    // Schema-enforced (@in defaults + required). Always returns a CTN string;
    // failures return a CTN: error block (code 400/404/500). Never throws, never false.
    $path = $this->mods[$id] ?? null;
    if (!$path || !is_file($path)) return $this->build_error('not_found', $id);
    $C = $this;
    ob_start();
    extract(is_array($args) ? $args : []);
    include $path;
    return ob_get_clean();
  }
  private function register_mods() { /* scan modules/ */ }
}
```

That's the whole Core. Registration + invocation.

**LLM drift risk:** The temptation to "simplify" by merging the Core with the web adapter. The temptation to add request / response / session logic directly in the Core for convenience. The temptation to add "boot" logic (configuration loading, middleware registration, event subscription) because "that's what frameworks do."

**Example (pass):**

```php
// cli.php — three lines of real work
require_once __DIR__ . '/containerist.php';
$C = new Containerist();
return $C;
```

```php
// scripts/generate-digest.php
$C = require __DIR__ . '/../cli.php';
$output = $C->ctn('daily-digest', ['date' => date('Y-m-d')]);
file_put_contents('daily-digest-' . date('Y-m-d') . '.txt', $output);
```

The Core does not know whether it is running under cron, a web request, or a REPL. Good.

**Example (fail):**

```php
// containerist.php — polluted with web concerns
class Containerist {
  function __construct() {
    session_start();
    $this->request = new Request($_SERVER);
    $this->response = new Response();
    // ...
  }
}
```

Now the Core cannot be used from CLI without `session_start()` running, which errors outside a web context. Realm leaked into Core. Reject.

---

## 7. Composition — How It All Hangs Together

The pillars are not independent. They cooperate. A worked example illustrates how.

### The four composition axes

CTN is the pivot type that enables composition at four levels:

1. **Stack-level composition (macro).** A stack lists containers. This is the page-level map. Reading the stack file tells you, in order, what will appear on the page. Flat, greppable, editable by whoever authors pages.

2. **Mod-internal composition (micro).** A mod may call other mods and concatenate their output into its own. This is like a shell script pipelining smaller programs. Hidden from outside the mod, and that hiding is appropriate — it's the mod's internal decomposition.

3. **Realm-external composition (cross-process).** Any mod can be invoked from any realm: shell (`./ctnr`), HTTP (`curl`), PHP script. Outputs can be piped (shell), chained via curl, or composed in PHP. Same API, different process boundary.

4. **Skin composition (render-time).** The skin renderer consumes CTN blocks and produces HTML+CSS. Flat iteration, one type per template pair.

All four rely on CTN being the pivot type. If any mod returned something other than CTN, one axis breaks.

### Concrete trace: `/n/K250624A`

Start to finish:

```
URL:               /n/K250624A
Realm:             web (index.php)

Parse:             parts = [n, K250624A]
Stack resolution:  try stacks matching [n, K250624A] with wildcards
                   → n--K250624A (not found)
                   → n--*         (found, use this)

Stack file:        stacks/n--*.txt
                   @args: note_id = {{2}} (binds to 'K250624A')
                   Listed containers:
                     note-header
                     note-title
                     note-body
                     note-tags
                     note-related
                     footer

Invocations:       for each container C:
                     $output .= $C->ctn(C, ['note_id' => 'K250624A'])

Per-mod:           note-header   → CTN: header   ...
                   note-title    → CTN: title    ...
                   note-body     → CTN: body     ...
                   note-tags     → CTN: taglist  ...
                   note-related  → CTN: related  ...
                   footer        → CTN: standard ...

Concatenated CTN:  6 blocks, flat

Skin render:       for each block B:
                     $html .= mustache(skin/{B.type}.html, B.fields)

Output:            HTML
```

Every step is readable, enumerable, and independently testable:

- Stack resolution is a file-scan + pattern match: testable with a URL string in; stack file out.
- Each mod is invocable standalone from any realm: `./ctnr note-title note_id=K250624A`.
- The CTN output is a string: diff-able against fixtures.
- The skin render is a fold: pure function of blocks + templates.

If any step fails, you fix that step alone. You don't need to reason about the whole chain.

### The reason this works

The architecture is glued together by CTN's type stability. Every composition boundary is the same interface: "produce or consume CTN blocks." The glue is not a framework API; the glue is a data format.

Most frameworks put the glue in code — a request lifecycle, a component tree, a rendering context object. Replacing or testing a piece of glue-code requires reasoning about every component that interacts with it. In Containerist, the glue is a string format. Replacing a mod means producing the same string format differently. Testing a mod means asking "does it produce the right string?" — a question answerable with `diff`.

That's the architectural lever. Protect CTN as pivot type; everything else follows.

---

## 8. Vocabulary

A glossary of terms used throughout this document. If you find yourself using these words differently, adjust your usage, not the definitions.

**CTN** — Container (the format). A block specification: `CTN: <type>`, YAML frontmatter, `---`, optional Markdown body. Multiple blocks can appear in one output. The fundamental unit of data in the system.

**CTN block** — One instance of a CTN spec — one type tag, one YAML frontmatter, one body. A container's output is zero or more blocks.

**Container** — A named source of CTN blocks. Implemented as either a *mod* (dynamic, .php) or a *static container* (plain text, .txt). The name is flat across both; the dispatcher resolves to mod first, static fallback.

**Mod** — Short for *modifier* (not *module*). A PHP file that transforms declared inputs (`@in:` comment header) into typed output. Located at `modules/**/*.<type>.php` (e.g. `.ctn.php`, `.html.php`) or `modules/**/*.php` (untyped). Named by its filename minus the type suffix + `.php`.

**Typed mods** — Filename convention declaring mod output type. `.ctn.php` → CTN (the composable default; downstream mods can consume it). `.html.php` → HTML (terminal; a boundary mod, not further composable). Future `.xml.php` / `.json.php` / `.rss.php` / etc. The Core recognizes type via filename and exposes `$C->mod_output_type($name)`. Callers always dispatch by mod *name* (stripped of type suffix); the type is a property of the mod, not part of its invocation ID.

**Core** — The CTN-producing subsystem: registers and executes mods; resolves container names (mod first, static fallback); parses CTN. ~80 lines. Named in pillar 8. Proper noun (capital C). Realm-freeness is enforced by construction: the Core has no code that talks to a realm.

**Static container** — A plain text file containing CTN blocks (or plain text, which defaults to a `standard` block). Located at `containers/**/*.txt`.

**Stack** — A flat list of container names composing a page. File at `stacks/*.txt`. The filename uses `--` as a separator and `*` as a URL wildcard.

**Stack file** — The text file representing a stack. Begins with `CTN: stack`, may declare `@args` bindings, then lists containers one per line after the `---` separator.

**Realm** — An invocation context for a mod. Three realms: HTTP (via web server), CLI (via `./ctnr`), and PHP (in-process `$C->ctn(...)` calls). A fourth boundary, mod-internal, is also a valid invocation site but not a realm in the external sense. One adapter per realm.

**Adapter** — The realm edge. Receives the realm-native request (HTTP, CLI command line, PHP call, MCP tool invocation), picks the renderer by suffix, calls the Core via `$C->ctn($id, $args)`, and performs the response (HTML + headers, stdout text + exit, redirect, status). The only realm-aware layer. One per realm: web, cli; future mcp. Reclaims the name *realm adapter*.

**Renderer** — `CTN → realm-neutral response`. Runs the acts and formats the output (html/raw/source/text). Realm-free; thin by discipline.

**Skin** — The render layer. A set of `.html` + `.css` pairs in `skin/`, one pair per CTN type. Renders CTN blocks into HTML via Mustache substitution.

**$C** — The Core instance. Available inside every mod as a local variable (injected by the Core via `$C = $this` before `include`). The primary use is calling other mods: `$C->ctn('name', $args)`.

**@in** — The declared input contract of a mod, written as a comment header at the top of the file. Replaces implicit argument sourcing with explicit declaration. Format: `// @in: name (qualifier[, qualifier…]), name2 (qualifier), name3`. Qualifiers: `required`, `optional`, `default: <literal>` (literal can be quoted string, number, `true`/`false`/`null`, or bareword). Empty declaration (`// @in:` or `// @in: (nothing)`) marks an explicit zero-input mod and passes lint cleanly. Parsed by `lib/ctn_in_parser.php`; enforced by `tools/lint-in.php`; consumed by the cli renderer dispatcher and by the web renderer's `stack-ctn`.

**@args** — Stack-frontmatter bindings mapping URL parts (or literals) to named args passed to every mod the stack invokes. Format: `@args: note_id = {{2}}, tag = {{3}}, greeting = "Hello"`. `{{N}}` refers to URL part N (1-indexed). Quoted strings are literal values. The adapter resolves bindings against URL parts before invoking each container; mods receive the resolved args as declared `@in` inputs. Per-line args on individual container references (STACK-SPEC §7.3) extend the same `{{…}}` grammar to a per-invocation scope.

**Control-flow CTN blocks** — Special CTN block types the renderer records and the adapter performs. A mod emits these to trigger realm-level behavior without reaching for `header()` or `exit`:
- `redirect` — fields `to` (required), `status` (default 302). Adapter issues HTTP redirect.
- `content-type` — field `value` (MIME type). Adapter sets Content-Type and emits block body raw (no skin). Used for RSS, JSON, XML responses.
- `error` — fields `code` (HTTP status), `message`. Adapter sets status code and renders the error block (skinned).

**Suffix dispatch** — URL-path suffixes the web adapter recognizes before running the HTML pipeline:
- (none) → full HTML page (via `stack-ctn` → `skinner-ctn` → `page-wrap`).
- `.htmx` → HTML fragment without page-wrap (for HTMX swaps).
- `.ctn` / `.raw` → raw mod/container output, `text/plain`. Last URL part = container id.
- `.stack` → raw stack-file source, `text/plain`. Wildcard-resolved stack_id.
- `.stackctn` → combined stack CTN (post-mod, pre-skin), `text/plain`. No control-flow interception, so redirect/error blocks show inline as data — inspection-faithful.

**Pillar** — A frozen architectural property of Containerist. Eight pillars are named in this document. Pillars are defended by discipline (and, for pillar 8, by construction). New pillars are added deliberately; existing pillars are not modified without explicit design decision.

**Criterion** — One of five review questions. Asked of every proposed change. A change that fails a criterion without justification is drift.

**Drift** — Accumulating change that degrades one or more pillars, usually unintentionally, usually in the direction of median training-data patterns (framework conventions, abstraction ladders, component hierarchies). The review criteria exist to catch drift before it compounds.

**Realm-agnostic** — A property of a mod (or any unit) that produces identical behavior regardless of which realm invoked it. The default for mods. The opposite is **realm-locked**.

**Modifier vs generator** — A modifier takes an input and transforms it. A generator produces output from nothing. Containerist mods are modifiers (with static containers as the degenerate zero-input case). Prefer the modifier framing even when the input is implicit — it disciplines sourcing.

---

### Shared data shapes

Structures that cross module boundaries. Documented here once so a future instance doesn't have to reconstruct them by reading every consumer.

**Args dict.** The associative array passed as the second argument of `$C->ctn($name, $args)`, and the shape of every mod's local scope after the Core's `extract()`.

```
$args = [
  // Named keys from @in bindings and stack @args:
  'id'     => 'K250624A-example-slug',   // a declared @in input
  'year'   => '2024',                    // a stack @args binding
  // Numeric keys (1-indexed) from URL parts, for wildcard-matched stacks:
  1 => 'n',                              // URL part 1
  2 => 'K250624A-example-slug',          // URL part 2
]
```

Rules:
- Named keys come from (a) stack `@args` bindings applied to URL parts or literals, or (b) the caller's explicit arguments when dispatching via `$C->ctn()`.
- Numeric keys (1-indexed, addressable as `{{1}}`, `{{2}}`, … in stack `@args` and per-line args) are always the raw URL parts for wildcard-matched web invocations. Provided as a fallback for mods without `@in`.
- A mod with `@in` declared receives only the keys it declares, plus the numeric fallback; undeclared named args are silently dropped by `ctn_in_apply_schema()`. Required-but-missing inputs raise `InvalidArgumentException`.

**Stack struct.** What `stack-ctn` (and the legacy `Request::resolve_stack()`) treat as a parsed stack.

```
$stack = [
  'path'       => 'stacks/n--*.txt',
  'containers' => ['kid-to-note', 'header', 'article', 'reply_by_email', 'footer'],
  'args'       => [
    // Raw @args bindings — unresolved.
    'id'       => '{{2}}',
    'greeting' => '"Hello"',
  ],
]
```

Rules:
- `containers` is an ordered list of container names (mod or static). Comments (`# …`) and blank lines are stripped during parse.
- `args` entries are raw binding specs: `$N` for URL part N (1-indexed), `"literal"` for quoted strings, bareword for unquoted strings. Resolution happens in `stack-ctn` against the caller's `args` input.

**CTN block struct.** What `$C->parse_ctn($string)` returns per block.

```
$block = [
  'type'   => 'standard',        // the string after "CTN: " on the header line
  'fields' => [                  // YAML-ish key:value frontmatter before `---`
    'class' => 'y02',
    'date'  => '2024-02-02',
    // Nested YAML is currently parsed as string; future pass may add arrays/maps.
  ],
  'body'   => "## Title\n\nMarkdown or HTML content",
]
```

Rules:
- A string with no `CTN:` header is a single `standard` block whose `body` is the whole string (pillar 1).
- `fields` is an associative array of scalar strings. `@args` in a stack block's frontmatter is captured as `fields['@args']`.
- `body` is raw text. For `skinner-block`, it's substituted into `{{body}}` (escaped) / `{{{body}}}` (raw) / `{{markdown}}` (Parsedown-rendered, escaped) / `{{{markdown}}}` (Parsedown-rendered, raw).

**@in schema.** What `ctn_in_parse_file($mod_path)` returns.

```
$schema = [
  'note_id' => ['required' => true,  'default' => null],
  'limit'   => ['required' => false, 'default' => 10],
  'format'  => ['required' => false, 'default' => null],
]
```

Rules:
- Returns `null` if the file has no `@in:` header. Returns `array()` (empty) if the header says `// @in:` or `// @in: (nothing)` — distinguishable states; the linter treats the latter as "explicit zero inputs," no warning.
- Qualifiers are recognized: `required`, `optional`, `default: <literal>`. Literals: quoted strings, numbers, `true`/`false`/`null`, bareword-as-string.
- **Defaults are literal scalars, parsed once at registration time.** They are *not* re-evaluated per call. `@in: date (default: today)` stores the string `"today"`, not today's date — tempting but wrong. Computed defaults (now, uuid, cwd, session-derived) belong in the mod body: declare the arg `(optional)` and compute on line 1, e.g. `$date = $date ?? date('Y-m-d');`. This keeps the `@in` header a pure, static contract and keeps the computation visible where a reader is already looking.

**Control-flow block payloads.** When a mod emits a control-flow CTN block, these fields are contract-bound — the adapter reads them:

- `CTN: redirect` → `to` (required, URL), `status` (optional, default 302).
- `CTN: content-type` → `value` (required, MIME type). Block `body` is the raw response.
- `CTN: error` → `code` (required, HTTP status 4xx/5xx), `message` (required, human text), `detail` (optional).

---

## 9. Frozen Constraints

What Containerist must not become. These are not suggestions. They are the frozen shape of the system. Violating them requires an explicit design decision, documented, not a silent refactor.

### What not to do — the twelve bans, with rationale

The operator briefing (`docs/containerist.md` §"What not to do") carries the canonical, terse list. The expansions below match that list one-for-one and add the *why*. If you're tempted to violate one of these, read its expansion before deciding.

1. **No middleware, hooks, filters, event buses.** A mod is called, produces output, stops. Nothing observes it from outside; nothing intercepts it. If you want to transform a mod's output, call it and transform the result — explicitly, at the call site. Middleware chains assemble execution paths at runtime that are not enumerable from source (the manifesto's principle 4); they also fragment one logical operation across files and call sites (criterion 4).

2. **No shared mutable state between mods.** No `$C` mutation, no globals, no carryover between mod invocations. Each mod is a function from `@in` args to CTN output. State that needs to persist across mods belongs in a CTN block (`session`, `flash`, …) the renderer handles, not in a back-channel a mod reads or writes directly. Shared mutable state breaks local verifiability (manifesto principle 8) and turns mod ordering into a hidden dependency.

3. **No realm globals in mods.** `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`, `$_COOKIE`, `$_REQUEST`, `$_FILES`, `STDIN` — banned inside a mod body. The adapter populates `@in`; mods consume inputs only. The moment a mod reads `$_GET['id']` it becomes web-locked: untestable from CLI, unreusable across realms, and pillar 6 (uniform invocation) collapses for that mod and everything that calls it.

4. **No nested CTN blocks.** Blocks are siblings in a flat list. A block does not contain another block. Nesting recreates the component tree we deliberately don't have (pillar 3) and forces every consumer (renderer, skinner, federation client) to walk a tree instead of iterating a list. Composition is achieved by emitting more sibling blocks, not by wrapping.

5. **No explicit `CTN: standard` header on static containers.** The implicit default *is* the feature. A plain text file with no `CTN:` header parses as a single `standard` block whose body is the file's contents — that's what makes "static container" a zero-ceremony authoring surface. Writing `CTN: standard` explicitly turns the simplest case into the same boilerplate as every other type and erodes pillar 1.

6. **No component trees, slots, layout engines.** One CTN type → one `skin/<type>.{html,css}` pair. No template inheritance. No `{{> partial }}` style nesting. Composition happens through CTN blocks, not through template hierarchies. The moment templates compose templates, you have invented JSX/Twig/Liquid by another name and pillar 3 is gone.

7. **No Composer, PSR-4, service locators, DI containers, ORMs.** The `register_mods` filesystem scan is the dependency graph. Adding Composer means adding a build step, a lock file, version conflicts, and a second loading mechanism alongside the first — none of which earn their cost in a small framework. ORMs are out of scope: storage is flat files; queries are filesystem walks or file reads; structured data lives in YAML frontmatter. DI containers and service locators are runtime indirection that hides where data comes from (manifesto principle 3).

8. **No abstract base mods or mod-interface hierarchies.** Mods are plain PHP files. If multiple mods share helper logic, the helper is a plain function in a `core/lib/` file, `require_once`'d where needed. A class hierarchy among mods turns "read one mod" into "read this mod plus its base plus the base's base" — the manifesto's principle 1 (minimize file fan-out) penalizes this directly. Composition over inheritance, every time.

9. **No mod modulates another mod.** A mod may *source* (call) another mod via `$C->ctn(...)` — that's pillar 6, normal composition. A mod may not *observe*, *override*, or *intercept* another mod. There is no plugin API because there is no need for one; adding functionality means adding mods, and changing functionality means changing the mod that owns the concern. The "modulate" ban prevents the slow drift toward a hooks/events/plugins surface.

10. **No mod knows its caller, placement, or realm.** A mod takes `@in`, returns typed output. It does not check whether it was invoked from the CLI, from a web request, from another mod, or from a stack body. It does not check which stack listed it. It does not check which mod called it. If a mod's behavior depends on context, the context is wrong place to read it from — the caller should pass the relevant input via `@in`. Otherwise, pillar 6 (uniform invocation) breaks.

11. **No side effects outside `@in` / output contract.** A mod's job, in full, is: read `@in`, emit CTN. If a mod legitimately needs a side effect — write to disk, send an email, mutate a session — its CTN output **must describe what happened** (e.g. emit `CTN: session set: ...` and let the act handle the actual write). Silent state changes are forbidden: they break local verifiability, hide dependencies, and make the same `@in` produce different observable outcomes depending on system state. Pushing side effects to acts is the manifesto's principle 8 made structural.

12. **No `__call` / magic dispatch on Core.** Always `$C->ctn('foo', ...)` explicitly. PHP's magic methods (`__call`, `__get`) hide where data and dispatch come from — the manifesto's principle 3 names this as a primary cost. The journal entry for 2026-04-19 documents the removal of `__call` from Core after a 60× cost-ratio analysis: defending the magic took ~165 lines of enforcement; deleting it took ~10. Keep it deleted. (5.1 further collapsed the two-method shape `$C->mod()` / `$C->ctn()` into a single `$C->ctn()` — see `containerist.md` §"Public Core API" for the always-CTN-string return contract.)

### Other things not to add

A handful of bans don't fit the twelve above but still hold:

- **No framework-level caching.** Caching, when needed, lives in a specific mod or an adapter — never inside the Core. Caching in the Core would violate realm-independence (a CLI invocation and a web invocation could observably diverge for the same `@in`).
- **No "plugins" or "extensions" as a distinct concept.** Adding functionality means adding mods. The plugin API is the mod registry; the plugin contract is `@in` + CTN.

### What not to change

- **The CTN block format.** It is a public contract across every composition axis. Changes to the format are backwards-incompatible and affect mods, stacks, skins, renderers, and adapters simultaneously.
- **The one-html-one-css per type mapping.** Breaking this gives you a layout engine. See above.
- **The flat-stack invariant.** See pillar 2.
- **The implicit-standard default for plain-text files.** See pillar 1.
- **The realm-free Core.** See pillar 8.

### What not to rename

- The word **mod**. It means *modifier*. Do not rename it to *module* — the word changes carry different expectations (modules are self-contained units with dependencies; modifiers are transformation filters, more Unix-y). The naming is load-bearing.
- The word **container**. It means *source of CTN blocks*, not *Docker container* or *DI container*. Keep the meaning.
- The word **stack**. It means *flat list of containers for one page*, not *stack data structure* or *technology stack*.

### Justified exceptions

A frozen constraint can be lifted — but only by explicit decision, named in a commit / PR / briefing revision. The decision must answer:

1. Which pillar does this affect?
2. What is the replacement architecture?
3. Why is the replacement better for the four simplicities, not just for this particular feature?

If you can't answer all three, the constraint stays.

---

## 10. Open Questions — Status

This section originally held a cluster of TBDs about the rework. Most are now resolved in working code (in `konnexus.net` and the realized intents in `direction.md`/`logs/`). Each entry below is marked ✓ resolved or ○ still open.

**Resolved:**

- **10.1 Declared-inputs syntax** ✓ — `// @in: name (qualifier[, qualifier…])`. Parser in `lib/ctn_in_parser.php`; linter in `tools/lint-in.php`.
- **10.2 Error-handling convention** ✓ — `CTN: error` block type with fields `code`, `message`, `detail`. Plus companion `redirect` and `content-type` control-flow blocks the renderer records and the adapter performs.
- **10.3 Stack → mod args-passing** ✓ — `@args: name = {{N}}` (1-indexed URL parts) or `name = "literal"`. Declared in place (formerly stack) frontmatter, resolved by `place-ctn` (formerly `stack-ctn`) mod. STACK-SPEC 2.0 grammar (`$N` replaced by `{{N}}` for language neutrality across implementations); see `PLACE-SPEC.md` (5.0+) and the archived `archive/4.x-stack-format/STACK-SPEC.md` (4.x) and `direction.md`.
- **10.4 `ctnr` wrapper simplification** ✓ — `ctnr` is now a bash shim that execs `adapters/cli/CliAdapter.php`. All mod-specific heuristics removed. Positional args map to `@in` fields in declared order; `key=value` pairs override; single JSON object also supported.
- **10.6 Edge/core mod split for realm I/O** ✓ (partially) — subsumed by control-flow CTN blocks. A mod can emit `CTN: redirect` to trigger an HTTP 301 without touching `header()`. The edge-mod pattern remains available for cases where input parsing itself is realm-specific (multipart upload), but most common cases (redirect, error, content-type) are now declarative CTN blocks.

**Still open:**

- **10.5 Skin placeholder convention** ○ — Current behavior: `{{field}}` = HTML-escaped, `{{{field}}}` = raw, `{{body}}`/`{{{body}}}` = block body, `{{markdown}}`/`{{{markdown}}}` = Parsedown-rendered body. Mustache sections for arrays still to be decided; currently handled by mods calling Mustache directly on sub-templates.
- **10.7 Debugging / tracing** ○ — The `?trace=1` idea isn't built yet. Suffix dispatch (`.stack`, `.stackctn`) gives some inspection capability already: `.stack` shows the resolved stack file, `.stackctn` shows combined CTN pre-render. A proper trace would additionally show which wildcard candidates were tried, which mod received which args, and where time was spent.

The detailed problem-statements and recommendations from the original 10.1–10.7 drafts are preserved below for context.

---

### 10.1 Declared-inputs syntax

**Question:** What is the final syntax for a mod's declared inputs?

**Options considered:**

- Comment-header: `// @in: note_id (required), limit (default: 10)`
- Docblock: `/** @in note_id {string} Required */`
- Variable initialization pattern: `$in = ['note_id' => $in['note_id'] ?? null, ...]` at top of mod
- Separate schema file next to the mod

**Recommendation:** Comment-header. One line per mod, grep-able, parseable by `ctnr` to auto-generate CLI help and by a linter to validate invocations. Low ceremony, high legibility.

**Example:**
```php
// @in: note_id (required), limit (default: 10), format (optional)
```

A simple parser can extract the three names and their attributes. The wrapper `ctnr` uses this to resolve positional args to keys. A linter can warn if the mod body uses a variable not declared in `@in`.

### 10.2 Error-handling convention

**Question:** What does a mod do when its input is invalid or its operation fails?

**Constraints:**

- The mod must return CTN (pillar 5). Silence or random errors break the uniform contract.
- The adapter may want to know that an error occurred (e.g. web returns 4xx, CLI returns non-zero exit).
- LLM-maintainability favors one convention, universally followed.

**Recommendation:** An `error` CTN block type. The mod returns a single block of type `error` with frontmatter fields `code`, `message`, and optional `detail`. Adapters inspect the output, and if the first (or only) block is of type `error`, take realm-appropriate action:

- Web: HTTP 4xx/5xx, render the error block via `skin/error.html`.
- CLI: print the block to stderr, exit non-zero.
- PHP: return the block as normal, caller may inspect.

Example:
```
CTN: error
code: 404
message: Note not found
detail: note_id=unknown-id
---
```

This keeps the contract uniform (all output is CTN) while signaling error state explicitly.

### 10.3 Stack → mod args-passing mechanism

**Question:** What is the final syntax for a stack declaring its args, and how are URL parts referenced?

**Resolved (STACK-SPEC 2.0, 2026-04-21; superseded by PLACE-SPEC 1.0+, 2026-05-21):** Positional with `{{N}}` notation (Mustache-style), 1-indexed, documented in `PLACE-SPEC.md` §5.1 (5.0+) and historically in `archive/4.x-stack-format/STACK-SPEC.md` §6.

```
CTN: stack
@args: note_id = {{2}}, filter = {{3}} (optional)
---
...
```

`{{N}}` refers to the Nth URL part (1-indexed). `{{name}}` refers to a name bound earlier in the same resolution step or by the caller. Multiple bindings are comma-separated. The grammar is deliberately realm-neutral — the same `{{…}}` tokens read naturally to a PHP developer, a Go developer, an information architect, and an LLM.

A prior draft used `$N` / `$name`; that form read language-ish (PHP-ish / shell-ish) and was replaced when a second implementation (`containerist-go`) made grammar portability load-bearing. See `direction.md` for the motivation trail.

For non-URL inputs (e.g. stacks invoked from other contexts), args come from the adapter's own realm-wiring (query string, POST body, CLI argv, MCP tool-call args) merged after `@args` resolution. `@args` itself never references realm-specific sources like `$_POST`.

### 10.4 `ctnr` wrapper simplification

**Question:** The current `ctnr` has mod-specific heuristics (`*ctn-parse*` → key is `ctn`, `*ingest*` → key is `file`, etc.). With declared `@in`, these heuristics can go. What is the replacement?

**Recommendation:** Read the mod's `@in` declaration. The first declared input name is the default for a single positional arg. Key=value pairs override. Example:

```bash
./ctnr note-title K250624A
# Equivalent to:
./ctnr note-title note_id=K250624A
# Because note-title declares: // @in: note_id (required)
```

If the mod has no `@in` or the declaration is ambiguous, the wrapper requires explicit key=value. No more heuristics.

### 10.5 Skin placeholder convention

**Question:** How do CTN block fields map to Mustache template variables?

**Current:** CTN frontmatter fields become template variables 1:1. A `title: "Hello"` in frontmatter becomes `{{title}}` in the template.

**Edge cases to resolve:**

- Multi-line body: is `{{body}}` the Markdown-rendered HTML, or the raw Markdown? (Recommendation: rendered HTML, with a separate `{{body_raw}}` available.)
- Nested YAML (e.g. `tags: [a, b, c]`): how are arrays rendered? (Recommendation: Mustache sections: `{{#tags}}{{.}}{{/tags}}`.)
- Escaping: CTN text comes from trusted sources (author-authored files), so Mustache's default auto-escape may or may not apply. (Recommendation: leave auto-escape on; use `{{{field}}}` for known-safe HTML.)

These are implementation decisions, not architectural. Document whatever is decided in the skin layer's local readme.

### 10.6 Handling of realm-specific side effects

**Question:** Mods like `post-save` have filesystem side effects. Mods like a web-form receiver have HTTP side effects (parsing multipart, redirecting). How are these structured to preserve realm-agnosticism?

**Recommendation:** Split mods into *core* and *edge* layers.

- **core mod** — realm-agnostic, takes declared inputs, does the business logic (including filesystem writes), returns CTN. (Lowercase to distinguish from the *Core* subsystem.)
- **Edge mod** — realm-specific, thin, receives realm-specific input (multipart, session, stdin), transforms it into declared args for the core mod, delegates.

Example:

```
modules/posts/post-save.ctn.php      (core, realm-agnostic)
modules/web/post-save-form.ctn.php   (edge, web-only: reads $_POST, calls post-save)
```

The core mod is invocable from CLI, PHP, and HTTP (via a query-string POST). The edge mod is only meaningful in the web realm. Both are honest: the edge mod's `@in` declaration includes "reads $_POST", making the realm-dependency explicit.

### 10.7 Debugging / tracing

**Question:** How does a future LLM (or you) quickly answer "what runs when I hit URL X?"

**Recommendation:** A `?trace=1` query parameter (or `--trace` CLI flag) that, instead of executing, prints the resolution trace:

```
URL: /n/K250624A
Stack resolved: stacks/n--*.txt  (pattern match: n--*)
Args: { note_id: "K250624A" }
Will invoke:
  1. note-header(note_id=K250624A)
  2. note-title(note_id=K250624A)
  ...
Expected output blocks: 6
```

Costs a few lines in the adapters. Buys enormous LLM-legibility.

---

## 11. How to Use This Document

### Fresh Cursor / Claude Code session

1. At session start, paste `docs/containerist.md` into the chat as context.
2. State the task.
3. The LLM works against the briefing's rules. If it proposes something that looks wrong, you now have a named pillar or criterion to point at.
4. If the task is nontrivial or touches architecture, the LLM should read `briefing-detailed.md` as well. Prompt it to.

### When to revise this document

- A pillar's formulation turns out to be wrong (a case it doesn't handle, a property it asserts but the code doesn't actually have).
- A new architectural property is added deliberately (not drift).
- The rework resolves a TBD in section 10.
- A frozen constraint is lifted by explicit decision (rare; documented).

Do not revise this document to match what the code accidentally became. Revise it only to match what the code *intentionally* is. The asymmetry is important: this document is the design authority; the code is the implementation. When they disagree, either the code changes or the design does — never silently.

### How pillars and criteria interact

The five review criteria are the operational check — fast, asked of every change. The eight pillars are the structural commitments — referenced when a criterion fails and you need to understand why.

Typical flow:

1. Propose change.
2. Run five criteria.
3. If one fails, identify which pillar is threatened.
4. Read that pillar's LLM drift section.
5. Either justify the exception (rare) or restructure the change.

Criteria catch drift. Pillars explain why drift matters.

### For the LLM

You are stateless. This document is how you inherit context. When you read it, you inherit a design that has been worked out deliberately over multiple iterations by an author who cares about the distinction between simplicity and simplification. Do not improve the design by drift. Do improve implementation details by named decision.

When in doubt, ask. When you must proceed without asking, err toward: fewer files, shorter files, more explicit contracts, less abstraction, more tests runnable from the command line, more code that does one thing.

The four simplicities are the target. Every other rule exists to protect them.

---

*This document and its companion `docs/containerist.md` are the design authority for Containerist. They are updated deliberately, not accidentally. If you are an LLM reading this: welcome. Work carefully. The author has built something he likes, and you are here to help maintain the thing he likes, not to replace it with what your training data suggests it "should" be.*
