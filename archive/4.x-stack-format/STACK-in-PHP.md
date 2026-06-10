# STACK-in-PHP — PHP Implementation Guide for STACK *(archived 2026-05-22)*

> **RETIRED.** This document describes the 4.x stack-file format, which Containerist 5.0 retired in favor of places. PHP implementers should read **`PLACE-in-PHP.md`** for the 5.0+ successor. This sidecar is preserved as historical reference material for anyone porting a 4.x PHP codebase or reading older instance code. The reference impl (`containerist-php` 5.0.0+) no longer ships any of the symbols this doc describes — `stack-ctn.ctn.php`, `stack-source.ctn.php`, `STACKS_DIR`, the `.stack` extension. See `RELEASE-5.0.md` for the upgrade path; the mechanical conversion table lives in `PLACE-SPEC.md` §13.

**Status:** Informative. This document shows how to implement `STACK-SPEC.md` (also archived, alongside) conformantly in PHP. **`STACK-SPEC.md` is authority for the 4.x format.** If this doc conflicts with the spec, the spec wins.

**Dated claims.** Any claim about a specific PHP version, library version, or runtime behavior below is dated so a future reader can see when the claim was made and verify it's still current.

---

## Target environment

- **PHP:** 7.4.x minimum; 8.x target. Same as `CTN-in-PHP.md`.

## Reference implementation

As of 2026-04-21, the PHP reference for STACK-SPEC is distributed across:

- **`reader.konnexus.net/stackers/web/Request.php::find_stack_id()`** — wildcard-priority URL-to-stack matching per `STACK-SPEC.md` §4.2 – §4.4. Today this implements STACK-SPEC 1.0 matching (pre-suffix-class-awareness); 2.0 alignment requires §4.1.5 suffix-classification logic.
- **`reader.konnexus.net/stackers/web/WebStacker.php::handle()`** — suffix dispatch for framework-reserved suffixes (`.ctn`, `.raw`, `.stack`, `.stackctn`, `.htmx`, `.trace`) and alias-default handling (today `.html` is an htmx-alias; STACK-SPEC 2.0 changes that to "same as no suffix").
- **`reader.konnexus.net/modules/core/stack-ctn.ctn.php`** — stack composition per `STACK-SPEC.md` §8. Parses stack file, resolves `@args`, iterates body lines, invokes each container.

Both PHP sites (`konnexus.net` and `reader.konnexus.net`) carry parallel copies of these files. A change lands in one and ports to the other.

## Notes for STACK-SPEC 2.0 migration

**Grammar migration (`$N` / `$name` → `{{N}}` / `{{name}}`).**

- Update `stack-ctn.ctn.php`'s `@args` resolver regex: today matches `$(\d+)` and `$(\w+)`; change to `\{\{(\d+|\w+)\}\}` (or two separate patterns). Literals (quoted strings, barewords) remain as-is.
- Mechanical migration of existing `stacks/*.txt` files: one `sed` pass over both site repos, with manual verification for edge cases (any literal `$` in stack body content, for example in a redirect URL).

**Per-line args.**

- Parse each body line: split on the first `?` that appears before any `:`. Left side is `<prefix?><name>`; right side is the query-string-shaped binding list.
- Use `parse_str()` to decode the query-string, then walk the result substituting `{{N}}` / `{{name}}` refs against the resolved args dict.
- Merge the per-line dict onto a *copy* of the resolved args (not onto the resolved args themselves — each body line needs a fresh composition per `STACK-SPEC.md` §8 step 3c).

**Suffix classes (§4.1.5).**

- Reserve dispatch suffixes in a class constant: `DISPATCH_SUFFIXES = ['ctn', 'raw', 'stack', 'stackctn', 'htmx', 'trace']`.
- Alias-default suffixes: read from config (`ALIAS_DEFAULT_SUFFIXES` constant, default `['html']`).
- Any other suffix → 404. This is a breaking change from today's "silently strip unknown suffix and fall through to suffix-less stack."

## YAML-key quoting for `@args`

Per `STACK-SPEC.md` §6.3: the `@args:` line starts with `@`, which strict YAML 1.2 parsers reject. PHP's Spyc accepts it silently; Symfony YAML 5.x/6.x also accepts it (verify on your version). If you migrate to a strict YAML parser, preprocess stack-file content in the Stacker before handing to the CTN parser: replace any line-start `@<word>:` with `"@<word>":`. Parsed map key remains `@args`.

## Testing

The `tools/smoke.sh` script in each PHP site covers URL-level cases (wildcard matching, suffix dispatch, schema enforcement, control-flow blocks). Extend it for STACK-SPEC 2.0 coverage: per-line args cases, `{{…}}` grammar cases, alias-default `.html` → same as no suffix, strict 404 on invalid suffix.

---

*This document evolves with PHP implementation state. Date any claim; update or remove when stale. STACK-SPEC.md is the target; this file explains how to hit it in PHP.*
