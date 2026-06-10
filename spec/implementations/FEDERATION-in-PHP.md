# FEDERATION-in-PHP — PHP Implementation Guide for FEDERATION

**Tracks FEDERATION-SPEC v2.0 as of 2026-04-24.**

**Status:** Informative. This document shows how to implement `FEDERATION-SPEC.md` conformantly in PHP. **`FEDERATION-SPEC.md` is authority.** If this doc conflicts with the spec, the spec wins.

**Dated claims.** Any claim about a specific PHP version, library version, or runtime behavior is dated so a future reader can see when the claim was made and verify it's still current.

---

## Target environment

- **PHP:** 7.4.x minimum; 8.x target. Same as `CTN-in-PHP.md`, `PLACE-in-PHP.md` (the 5.0+ successor to the archived `STACK-in-PHP.md`).
- **Extensions:** `curl` (required, for HTTP fetch), `mbstring` (assumed available — used by Mustache substitution). Both are part of default PHP installations on Ubuntu/macOS/Homebrew.

## Reference implementation

As of 2026-04-24 (FEDERATION-SPEC v2.0), the PHP reference is distributed across:

- **`containerist-php/core/lib/containerist/federation.php`** — the federation client. Single-file library, ~330 lines. Public surface: `federation_is_ref`, `federation_substitute_url`, `federation_resolve`, `federation_strip_denylisted_blocks`. Internal: LRU cache store (static inside `federation_cache_store`, shape documented inline), per-request fetch chain (static inside `federation_fetch_chain`), HTTP primitive (`federation_http_get`), ttl derivation, error emission. Accepts an injectable fetcher parameter for tests.
- **`containerist-php/core/mods/stack-ctn.ctn.php`** — the consumer-side integration point. The body-line loop pre-checks each line for federation shape; direct federation refs short-circuit `stack_parse_ref_line` and route through `federation_resolve`. Deferred form (`htmx: http://...`) emits `CTN: htmx-placeholder` pointing at the consumer's fragment endpoint. Inlined CTN output gets the same implicit-`standard`-wrap treatment as local mod output.
- **`containerist-php/core/config.defaults.php`** — the nine `FEDERATION_*` constants (allowlist, cache enabled, timeout, max size, default TTL, cache size, depth limit, user agent, block denylist). All default-guarded so any instance can override.
- **`containerist-php/core/renderers/acts/error.php`** — the `origin:`-sensitive branch that keeps federation errors from mutating the page's HTTP status (§9.6).
- **`containerist-php/skin/error.html`** — the `{{#origin}}` attribution block per §9.5.
- **Fragment endpoint for deferred federation (§14).** Implementation-specific URL; PHP reference uses a mod at a conventional path like `/fed.htmx?ref=<urlencoded-producer-url>`. The mod accepts `ref` as `@in`, validates allowlist, invokes `federation_resolve`, skinner-block-renders the result, returns an HTML fragment (`Content-Type: text/html`).

Both PHP sites (`containerist-php`, `konnexus.net`) carry parallel copies. A change lands in the reference and ports to the instance.

---

## Integration point (the five lines that matter)

The federation detection branch in `stack-ctn.ctn.php` is the load-bearing consumer-side integration:

```php
$subst = federation_substitute_url($line, $args, $resolved);
if (federation_is_ref($subst)) {
  $split    = federation_split_line($subst);
  $per_line = stack_parse_per_line_args($split['args_str'], $args, $resolved);
  $out      = federation_resolve($split['url'], $per_line);
  // inline $out exactly like local mod output
  continue;
}
```

Critical: **substitution runs before detection; detection runs before `stack_parse_ref_line`.** If you let `stack_parse_ref_line` see the line first, it parses `http://foo` as a `http`-prefixed name (because `:` appears before any `?`), which falls through to the "unknown prefix" error branch. The spec orders these deliberately (§4.4, §4.1); the implementation follows the same order.

## Fetch primitive

Use `curl_exec`. Relevant options (as of 2026-04-24, PHP 8.x):

```php
CURLOPT_RETURNTRANSFER  true
CURLOPT_TIMEOUT         FEDERATION_TIMEOUT          // total, not just connect
CURLOPT_CONNECTTIMEOUT  FEDERATION_TIMEOUT
CURLOPT_FOLLOWLOCATION  false                        // redirects rejected in v1
CURLOPT_USERAGENT       FEDERATION_USER_AGENT
CURLOPT_ENCODING        ''                           // let curl decompress gzip transparently
CURLOPT_HEADERFUNCTION  capture headers into a dict
CURLOPT_WRITEFUNCTION   size-guarded body accumulator
```

The size guard is the non-obvious part. Do NOT set `CURLOPT_MAXFILESIZE` — it's advisory and doesn't work for servers that don't send `Content-Length`. Instead, use `CURLOPT_WRITEFUNCTION` with an in-closure accumulator; return 0 from the callback when the accumulator exceeds `FEDERATION_MAX_SIZE` and curl aborts the transfer. Surface the overflow separately so the `CURLE_ABORTED_BY_CALLBACK` error becomes a `CTN: error code: 413` instead of a generic 502.

Timeouts: curl sets `errno = CURLE_OPERATION_TIMEDOUT = 28` on timeout. Distinguish from other transport failures (connection refused, DNS) so timeouts become `code: 504` while other failures become `code: 502`.

## Cache implementation

Single-process LRU, kept in a static inside a function that returns a reference:

```php
function &federation_cache_store () {
  static $store = array();  // key => [body, etag, expires_at]
  return $store;
}
```

On hit, delete-and-reinsert to move the entry to end (PHP array iteration order is insertion order — cheapest LRU you can get). On write, evict-from-front until within `FEDERATION_CACHE_SIZE`.

Process-local is the right scope for v1. Under PHP-FPM each worker has its own cache; under Apache prefork same. No cross-worker sharing; spec §8.2 says not required in v1. A future shared-store backend (APCu, Redis) is a spec revision.

Cache key: canonicalize the URL by sorting query params. `federation_cache_key()` does this with `parse_str` + `ksort` + `http_build_query`. Don't hash the key (sha256) unless profiling shows a real string-compare cost — human-readable keys make debugging cheaper.

TTL: prefer producer's `Cache-Control: max-age=N` when present, else `FEDERATION_DEFAULT_TTL`. Honor `no-store` by skipping the cache-put entirely. `no-cache` is not distinguished from a normal cache entry in v1 (§8.4 doesn't mention it).

`FEDERATION_CACHE_ENABLED = false` skips both the read and the write paths. Every call re-fetches. Intended for memory-constrained / shared-hosting / debugging cases. Document the performance cost loudly in the config.

## Error emission

Every failure produces a `CTN: error` block via `federation_emit_error($code, $origin, $message)`. The function strips newlines from `origin` and `message` so a malicious producer can't inject frontmatter fields. Fields in emission order:

```
CTN: error
code: <int>
origin: <url>          ← presence identifies this as a federation error
message: <string>
---
<optional detail body>
```

The `origin` field is load-bearing: the error act (`core/renderers/acts/error.php`) checks for it to decide whether the error should mutate the page's HTTP status. A federation-origin error is treated as "one foreign block failed to transclude" (page stays 200); a local error (`container:` field, or no origin) sets status per the block's `code`. This is a small spec gap — §9 doesn't explicitly specify page-status semantics for federation errors. The PHP reference picks "federation error → inline only, page status unchanged" because the rest of the stack rendered fine; the decision is noted inline in the act's header comment.

## Block-type semantics in federation output

The producer's response is parsed as CTN by the consumer's existing `Containerist::parse_ctn()`. Any block type the producer emits is available to the consumer. Three implications to remember:

1. **Control-flow blocks cross the boundary.** If a federated producer emits `CTN: title`, `CTN: redirect`, or `CTN: session`, those blocks reach the consumer's act dispatch. `CTN: title` updating the consumer's page title is likely fine (author-attribution propagates). `CTN: redirect` from a federated source would hijack the consumer's page — probably NOT what anyone wants. The reference doesn't currently filter; treat this as a known unsafe surface for any allowlisted producer that might be hostile. Allowlist discipline (§13.1) is the only control in v1.

2. **The implicit-`standard` wrap (CTN-SPEC §4.1) applies.** Non-CTN-prefixed producer responses are wrapped as `CTN: standard` at composition time — same rule as local mod output. Producers should emit `CTN:` explicitly; this is defensive for producers that don't.

3. **Page-title last-write-wins means order matters in the stack.** If your consumer stack has `page-title?value=My Page` *before* a federation ref that returns `CTN: title`, the federated title wins. Put consumer-owned `page-title` after federation refs to protect it. Or don't emit `CTN: title` from federation producers — but you can't control that remotely.

## Config example

Minimal instance `config.php` for a consumer:

```php
<?php
define('DOMAIN',   'stitchson.net');
define('SITE_URL', 'https://stitchson.net');

// Federation allowlist (FEDERATION-SPEC §6). Scheme+host must match
// exactly. Empty by default — federation is opt-in per origin.
define('FEDERATION_ALLOWLIST', array(
  'http://konnexus.net',
));

// All other FEDERATION_* constants take their defaults from
// core/config.defaults.php. Override only what you need.
```

Uncommon overrides, when they'd apply:

- `FEDERATION_TIMEOUT` — raise for slow producers; lower to fail faster if your page-rendering budget is tight.
- `FEDERATION_MAX_SIZE` — raise if a specific producer returns larger responses (e.g., konnexus `notes.ctn?term=stitches` is ~250 KB at the time of writing, right at the default cap).
- `FEDERATION_DEFAULT_TTL` — raise for stable archival producers; drop to 0 in dev config to effectively disable cache during producer iteration.
- `FEDERATION_CACHE_ENABLED = false` — global kill-switch. Memory-constrained / shared-hosting / debugging only. Do not disable in any deployment that gets real traffic (see spec §8.1).

## Testing

At the time of writing (2026-04-24), federation is **not yet covered by `smoke-http.sh`** in containerist-php. Manual verification via dev server + curl was used to confirm the happy path, allowlist miss, and upstream-404 → 502 collapse. See the 2026-04-24 log for the coverage gap list.

When extending `smoke-http.sh` (or building `conformance/federation/`):

- **A fixture producer is required.** Real external producers (konnexus.net) are fine for smoke-by-observation but break deterministic testing. A minimal fixture producer is a `php -S`-backed script returning configurable CTN + headers.
- **LRU per-process resets between PHP built-in server requests.** Every request spawns a fresh PHP process, so cache-hit assertions fail under `php -S`. Under PHP-FPM / Apache prefork the cache persists. This is a testing artifact, not a bug — either use PHP-FPM for cache-hit tests, or test cache behavior at the function level with a local `federation_resolve` call sequence.
- **Size overflow (413) is tricky to exercise** because curl's `CURLOPT_WRITEFUNCTION` abort returns `CURLE_ABORTED_BY_CALLBACK`, not a distinct "size exceeded" error. The implementation guards this via an `$over` flag set inside the callback; distinguish 413 from other `false`-return cases before mapping to an error code.

## Fed-rebase implementation (spec §13)

As of 2026-04-24 the rebase pass is implemented as a set of regex-driven rewrites applied to the producer response body after content-type check and before cache store (spec §5 step 8). Policy comes from the stack's `@federation` frontmatter; default is `to-producer`.

Two categories, per §13.4:

**Navigation (per policy).** Under `to-producer`, rewrite root-relative `/…` to producer origin in:
- Markdown link targets — regex `\[([^\]]*)\]\(/(?!/)([^)]*)\)`
- HTML `<a href="/…">` — bounded lookbehind to tag name (enclosing `<a` or `<area`)
- HTML `<form action="/…">`, `<button formaction="/…">`, `<input formaction="/…">`
- Frontmatter URL fields (`url`, `href`, `canonical`) at any depth

Under `to-consumer` and `off`, leave unchanged.

**Resource (always to producer except `off`).** Rewrite `/…` to producer origin in:
- Markdown image targets — regex `!\[([^\]]*)\]\(/(?!/)([^)]*)\)`
- HTML `<img src>`, `<script src>`, `<iframe src>`, `<audio src>`, `<video src>`, `<source src>`, `<embed src>`, `<track src>`, `<video poster>`, `<object data>`, `<link href>`, `<img srcset>`, `<source srcset>`

The `<link>` vs `<a>` distinction for `href` requires element-context awareness. In PHP the reference uses a bounded lookbehind from the `href=` match to the previous `<` character, then inspects the tag name. Cheap enough for well-formed producer content; brittle for malformed HTML, but malformed-HTML producers are failing spec §3.1 already (Content-Type enforcement).

**srcset parsing.** Comma-separated URL+descriptor pairs. Split on commas, rewrite each URL independently, reassemble.

**Cache-key policy component** (§8.3, §13.6). Cache key is `<canonicalized-url>|<policy>`. The `|` separator is URL-illegal; safe.

## Deferred federation implementation (spec §14)

The `htmx:` prefix extended to foreign URLs (§14.2) routes through the consumer's existing htmx-placeholder mechanism with a new fragment endpoint.

**Stack-render phase (in `stack-ctn.ctn.php`).** When the body-line loop sees `htmx: http...`, it emits a placeholder:

```
CTN: htmx-placeholder
container: fed
url: /fed.htmx?ref=<urlencoded-producer-url>
---
```

Where `fed` is a mod the consumer provides that accepts the `ref` arg and drives `federation_resolve`.

**Fragment-request phase.** When the browser requests `/fed.htmx?ref=...`, the consumer's web adapter handles the `.htmx` suffix for the `fed` mod. The mod reads `ref` from `@in`, validates allowlist (same `federation_resolve` path), runs the full pipeline, returns rendered HTML fragment.

**Cache key unification.** Both direct (`stack-ctn`'s federation branch) and deferred (the fragment endpoint mod) call `federation_resolve($producer_url, $per_line_args, $fetcher)` with the same canonicalized producer URL, so cache keys collide cleanly — the same URL cached under the same policy serves both paths.

**Content-Type distinction.** `.htmx` suffix dispatch normally returns `text/html` (HTMX swaps HTML); `.ctn` dispatch returns `text/plain`. The fragment endpoint mod follows the `.htmx` convention.

## Block denylist implementation (spec §15.6)

As of 2026-04-24 the reference strips four block types from federated responses: `redirect`, `session`, `flash`, `content-type`. Default list lives in `FEDERATION_BLOCK_DENYLIST`. Strip happens before cache store so cached bodies are pre-filtered.

Implementation: `federation_strip_denylisted_blocks($ctn, $denied_types)` does a line-based scan for `CTN: <type>` headers, collects lines until next header or EOF, drops blocks whose type is in the deny list. Preserves plain-text prologue (implicit `standard` blocks per CTN-SPEC §4.1). ~40 lines.

## Page-status semantics on federation error (spec §9.6)

`core/renderers/acts/error.php` checks for the presence of the `origin:` field on the block's fields. If present (federation error), the act renders the block via `skinner-block` but does NOT mutate `$response['status']`. If absent (local error), the old behavior persists: page status becomes the block's `code`.

Testable: a stack with only a local `CTN: error code: 404` returns HTTP 404 at the page level; a stack with a federated producer returning 404 (collapsed to `CTN: error code: 502 origin: ...`) returns HTTP 200 at the page level, with the error rendered inline.

## Injectable fetcher (test seam)

`federation_resolve($url_raw, $per_line_args = array(), $fetcher = null)` accepts a callable in the third position. Signature matches `federation_http_get()` — takes `$url, $if_none_match` and returns either `[status, body, hdrs]` on HTTP success or `['_err', code, message]` on transport failure. Default when null: `federation_http_get`.

Test usage:
```php
$fake = function ($url, $etag) {
  return array(200, "CTN: standard\n---\nhello", array('content-type' => 'text/plain'));
};
echo federation_resolve('http://fake.test/x.ctn', array(), $fake);
```

The cache and allowlist paths run identically under injected fetcher; exercises 413 / 415 / 504 / 304 / arbitrary status without a live producer.

## YAML hazards (port from `PLACE-in-PHP.md` and `CTN-in-PHP.md`)

When a federation-demo mod (e.g., `federation-label.ctn.php`) emits a URL into a frontmatter field, use `json_encode(..., JSON_UNESCAPED_SLASHES)`:

```php
echo "url: " . json_encode($url, JSON_UNESCAPED_SLASHES) . "\n";
```

Without `JSON_UNESCAPED_SLASHES`, you get `url: "http:\/\/konnexus.net\/..."` — Spyc parses it as a string, but depending on downstream use the escape characters may or may not survive into rendered output. `JSON_UNESCAPED_SLASHES` produces `url: "http://konnexus.net/..."` which is stable through YAML + Mustache.

Same discipline applies to any free-form string emitted into YAML. Colons in user-supplied values are the other hazard (`value: demo: title` parses as a nested mapping, not a string); the `page-title` mod's header comment calls this out.

---

*This document evolves with PHP implementation state. Date any claim; update or remove when stale. FEDERATION-SPEC.md is the target; this file explains how to hit it in PHP.*
