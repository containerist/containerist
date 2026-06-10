# CTN-in-PHP — PHP Implementation Guide for CTN

**Status:** Informative. This document shows how to implement `CTN-SPEC.md` conformantly in PHP. **`CTN-SPEC.md` is authority.** If this doc conflicts with the spec, the spec wins.

**Dated claims.** Any claim about a specific PHP version, library version, or runtime behavior below is dated so a future reader can see when the claim was made and verify it's still current.

---

## Target environment

- **PHP:** 7.4.x minimum; 8.x target. As of 2026-04-21 both `konnexus.net` and `reader.konnexus.net` run on PHP 7.4 (MAMP dev; prod Apache + PHP 7.4.33).
- **YAML library:** Symfony YAML (`symfony/yaml`, 5.x or 6.x) is the recommended parser. Produces native-typed scalars out of the box — aligned with CTN-SPEC §8.2 core schema. Spyc (0.5) is acceptable for legacy sites but has YAML 1.1 surface-form coercions that make scalars like `NO`, `1.10`, `022` surprise authors; see `CTN-SPEC.md` §8.2 authoring discipline for the quoting rules that cover both parsers.

## Required extensions

- **`php-xml`** if the site uses `simplexml_load_string()` (e.g. `reader.konnexus.net/modules/dailyreader/digest-create.ctn.php` parses RSS feeds). Missing extension surfaces at runtime, not at boot, under the current `mod()` `Throwable`-swallow behavior.

## Recommended implementation pattern

- CTN parser: single-pass split at lines beginning with `CTN:`, YAML-parse the frontmatter region, leave the body as an opaque string. Pure function, no global state.
- Input: `string` (the CTN document as bytes). Output: `array` of blocks, each block an associative array with keys `type`, `fields`, `body`.
- Line-ending normalization (`\r\n` / `\r` → `\n`) before framing; trailing `\n` on body stripped per spec §4.2.
- YAML empty / null-document result MUST normalize to `[]` (empty array) per spec §4.2.3.

## Historical PHP implementations

Two PHP parsers exist in the wild. Neither is a clean model to transliterate. Both are informative about what CTN *is*.

### reader.konnexus.net (pre-manifesto, pre-LLM-first)

**Production files are the corpus.** The reader's static containers at `containers/digests/*.txt` are real-world CTN in use: nested mappings under `items:`, quoted strings, flow-style sequences, YAML comments. This corpus established what the format has to support. `CTN-SPEC.md` was realigned against it on 2026-04-20 after an initial flat-scalar draft proved insufficient.

**The parser code is not a reference.** `reader.konnexus.net/lib/containerist/ctn.php` predates both the Containerist manifesto and the LLM-first manifesto. It uses Spyc (YAML 1.1-ish, core-schema-ish, typed-scalar surprises), employs PHP idioms the manifesto later banned (dynamic properties via `$this->$key = $value`, loose constructor signatures, bare `GLOBAL` in the wider tree), and produces output that does not conform to `CTN-SPEC.md` §8 without caveats. Future implementations MUST NOT transliterate it. Reading it to understand historical behavior on specific inputs is fine; the spec supersedes any observed behavior in this parser.

### konnexus.net (post-manifesto, Containerist 4.2)

`konnexus.net/containerist.php::parse_ctn()` does NOT call a YAML parser. It implements a flat-scalar approximation: `explode("\n")` plus first-colon split plus `trim()`. This parser will silently mangle any frontmatter that uses sequences, nested mappings, quoted strings with colons, or YAML comments. The `lib/yaml/yaml.php` (Spyc) is vendored but unused by the CTN parser.

This is a known defect; see `BUG-parse-ctn-yaml.md` in the same repo (tracked via the Go-port decisions for blocking reasons — the Go port needs the PHP ground truth to diff against). Remediation: replace `parse_ctn()` with a Symfony YAML call; core-schema output is used directly, no post-processing.

## Conformance status (as of 2026-04-21)

- **reader.konnexus.net** — Spyc-based; aligned with `CTN-SPEC.md` §8.2 modulo YAML 1.1 surface coercions. Authors handle the coercions by quoting ambiguous scalars per §8.2 authoring discipline. A short-lived failsafe post-processing step (`lib/containerist/yaml_failsafe.php`) was removed 2026-04-20; its cost at the Mustache-rendering layer outweighed its benefit.
- **konnexus.net** — `containerist.php::parse_ctn()` is non-conformant. Does not call any YAML parser. Pending remediation per above.

## `@in` parser (PHP)

See `IN-in-PHP.md` for the PHP-specific implementation of `IN-SPEC.md`. The short version: a line-header scan of the first ~30 lines of each mod file, regex-matched against `// @in: <payload>`, parsed into a schema array, applied via `array_intersect_key()` + `extract()` inside the mod invoker. Reference code: `reader.konnexus.net/lib/ctn_in_parser.php`.

## Place parser (PHP)

See `PLACE-in-PHP.md` for the PHP-specific implementation of `PLACE-SPEC.md` (5.0+, canonical). Reference code: `containerist-php/core/mods/place-ctn.ctn.php` + `containerist-php/core/stackers/web/Request.php::find_stack_id`. The 4.x stack-file parser is documented in the archived `archive/4.x-stack-format/STACK-in-PHP.md` for anyone porting a pre-5.0 codebase.

---

*This document evolves with PHP implementation state. Date any claim; update or remove when stale. CTN-SPEC.md is the target; this file explains how to hit it in PHP.*
