# CTN Conformance Fixtures

Per `CTN-SPEC.md` §9, each fixture is a pair:
- `<name>.ctn` — the input CTN document (byte-exact).
- `<name>.json` — the expected parse output, a JSON array of `{type, fields, body}` objects per §5 canonical shape.

A conformant parser MUST produce output semantically equivalent (§5) to the expected JSON for every fixture input.

## Fixtures

Filenames encode the `CTN-SPEC.md` §6 edge case they cover:

| File | §6 case | Covers |
|---|---|---|
| `01-empty.*` | §6.1 | Empty input → `[]` |
| `02-plain-text.*` | §6.2 | No `CTN:` substring → one `standard` block |
| `03-whitespace-only.*` | §6.3 | Whitespace-only implicit form |
| `04-empty-frontmatter-with-body.*` | §6.4 | `CTN: standard\n---\nbody` |
| `05-flat-scalar.*` | §6.5 | Scalar frontmatter field |
| `06-no-separator.*` | §6.6 | No `---`, fields only, body empty. Native-typed `status: 302` |
| `07-nested-sequence.*` | §6.7 | Sequence of mappings — real parser required |
| `08-multiple-blocks.*` | §6.8 | Concatenated explicit blocks |
| `09-type-only.*` | §6.9 | `CTN: done` with no newline |
| `10-leading-discarded.*` | §6.10 | Prelude before first `CTN:` is dropped |
| `11-quoted-special-chars.*` | §6.11 | Colons + escaped quotes inside quoted YAML strings |
| `12-yaml-comment.*` | §6.12 | `#` comment lines stripped by YAML |
| `13-separator-whitespace.*` | §6.13 | `  ---  ` tolerated after trim |
| `14-crlf.*` | §6.14 | Windows line endings normalized |
| `15-malformed-yaml.*` | §6.15 | Sentinel per §4.5 |
| `16-flow-style.*` | §6.16 | Flow-style inline mapping + sequence |

## The `_yaml_error` sentinel (§4.5) and fixture 15

The exact error message on malformed YAML is parser-specific (differs across the `yaml` npm pkg, PHP's Spyc, Go's `yaml.v3`, etc.). Fixtures that exercise §4.5 use the placeholder string `"__ANY_STRING__"` as the `_yaml_error` value. **Test runners MUST normalize** by replacing any non-empty `_yaml_error` string with `"__ANY_STRING__"` on both sides before comparison. See `containerist-ts/test/conformance-ctn.test.ts` for a reference runner.

## Byte-level precision

Several fixtures require no trailing newline (02, 09) or contain `\r\n` (14). Use `wc -c` to verify byte counts if editing. Expected sizes:

```
01-empty.ctn                  0 bytes
02-plain-text.ctn            12 bytes
03-whitespace-only.ctn        5 bytes  (3 spaces + 2 newlines)
09-type-only.ctn              9 bytes  (no trailing newline)
14-crlf.ctn                  31 bytes  (CRLF line endings)
```

## Adding fixtures

New edge cases discovered after CTN-SPEC 1.2 become normative once added. Authors adding a fixture must:

1. Write the `.ctn` input and `.json` expected output.
2. Update this README with a one-line entry.
3. Run all three reference implementations (PHP, TS, Go) against it.
4. If any implementation fails, either fix the impl or revise the fixture — never leave the fixture in a state no impl passes.
