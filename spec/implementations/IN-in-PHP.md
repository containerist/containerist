# IN-in-PHP — PHP Implementation Guide for `@in`

**Status:** Informative. This document shows how to implement `IN-SPEC.md` conformantly in PHP. **`IN-SPEC.md` is authority.** If this doc conflicts with the spec, the spec wins.

**Dated claims.** Any claim about a specific PHP version, library version, or runtime behavior below is dated so a future reader can see when the claim was made and verify it's still current.

---

## Target environment

- **PHP:** 7.4.x minimum; 8.x target. Same as `CTN-in-PHP.md`.

## Carrier syntax

In PHP, an `@in` declaration lives as a line comment at the top of the mod source file:

```php
<?php
// @in: note_id (required), limit (default: 10), admin (optional)
```

Block-comment forms (`/* @in: ... */`) are also acceptable; the parser scans for any line in the first ~30 lines whose trimmed value starts with `@in:` after comment-stripping.

## Reference implementation

`reader.konnexus.net/lib/ctn_in_parser.php` implements `IN-SPEC.md` 1.0 for PHP. Three top-level functions:

- `ctn_in_parse_file($file_path)` — scans the first ~30 lines of the file, finds the `@in:` line, returns the schema array (or `null` if undeclared, or `[]` if explicitly empty).
- `ctn_in_parse_declaration($str)` — parses the payload into an associative array keyed by input name.
- `ctn_in_apply_schema($args, $schema)` — applies the schema to a caller's args: fills defaults, throws `InvalidArgumentException` on missing required.

The schema shape:

```php
[
  'note_id' => ['required' => true,  'default' => null],
  'limit'   => ['required' => false, 'default' => 10],
  'admin'   => ['required' => false, 'default' => null],
]
```

## Integration with the mod invoker

In `containerist.php::mod()`, the canonical PHP pattern is:

```php
$args = ctn_in_apply_schema($args, $schema);
$args = array_intersect_key($args, $schema);  // filter unnamed keys
extract($args, EXTR_SKIP);                    // bind declared names into local scope
require $mod_path;                            // run the mod body
```

The `array_intersect_key` + `extract` pair is the structural enforcement of `IN-SPEC.md` §5.6 (unnamed caller args never reach the mod body). The mod's body sees only locally-bound declared names; undeclared keys are silently dropped before `extract` runs.

## Idiom notes

- **`@in`-less files** — if `ctn_in_parse_file` returns `null`, the implementation chooses how to handle it. Current PHP sites treat `null` as "legacy mod, admit all caller args unfiltered" for backward compatibility. This is a legacy permissiveness; a stricter mode (reject `null`) is a site-config concern, not a spec concern.
- **Explicit empty** (`// @in: (nothing)` or `// @in:`) returns `[]`, which the implementation must distinguish from `null`. Today's parser does this correctly.
- **Qualifier parser unknown-qualifier handling** — today's `ctn_in_parse_qualifier` silently ignores unknown qualifier tokens. `IN-SPEC.md` §6.9 requires that unknown qualifiers be surfaced. The reference PHP implementation should be updated to throw on unknown qualifier.
- **List- and map-shaped defaults go in the body, not the header.** Per `IN-SPEC.md` §4.2, `default:` literals are scalar-only — `(default: [])`, `(default: {a: 1})`, and similar are not in the grammar. When a mod needs a list- or map-shaped default, declare the arg `(optional)` and initialize on line 1 of the body. The canonical PHP idiom uses the null-coalescing operator, which safely handles both "arg absent" and "arg present but null":

  ```php
  <?php
  // @in: tags (optional), filters (optional)

  $tags    = $tags    ?? array();        // list default
  $filters = $filters ?? array('lang' => 'en');  // map default
  ```

  Same idiom also covers optional scalar args without declared defaults (e.g. `// @in: user (optional)` followed by `$user = $user ?? null;`), so mod authors already know the pattern — list/map defaults are just a trivial extension of it.

- **Stacker-injected names.** The Web Stacker populates a small set of well-known `@in` names from per-request facts (ACTS-SPEC §6, §6.1). A mod that declares one of these names receives the value automatically; framework injection happens at arg-lifecycle position 4. Currently:

  | Name | Source | Resolver | Notes |
  | --- | --- | --- | --- |
  | `user` | `$_SESSION['user_id']` (or richer `$_SESSION['user']`) | `core/lib/containerist/session.php` | `null` when unauthenticated |
  | flash entries | `$_SESSION['_flash'][<key>]` | `core/lib/containerist/session.php` | one-shot; drained after injection |
  | `bearer` | `Authorization: Bearer <token>` request header | `core/lib/containerist/auth_header.php` | absent when no Bearer header sent (since framework 4.4.1) |

  Mods declare these as ordinary optional args (`@in: user (optional)`, `@in: bearer (optional)`). The framework only resolves; validation/authorization is per-mod. Avoid these names for purposes other than the documented one — collisions silently work but mislead readers.

## What this spec version does NOT require (and the PHP implementation need not add)

- No `=` source syntax. A bare `name` with qualifiers is the full grammar. Do not extend the grammar to accept `name = $1` or `name = $query` — see `IN-SPEC.md` §4.3 and `direction.md` § Decisions not to do.
- No forward references between declarations. Each declaration resolves independently.

## Testing

Current coverage lives in `reader.konnexus.net/tools/lint-in.php` (a lint check over all mod files' `@in` declarations) and the fixture-based mod tests in `test/fixtures/`. Extend with unit tests over `ctn_in_parse_declaration` for every edge case enumerated in `IN-SPEC.md` §6.

---

*This document evolves with PHP implementation state. Date any claim; update or remove when stale. IN-SPEC.md is the target; this file explains how to hit it in PHP.*
