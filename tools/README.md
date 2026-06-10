# Containerist Tools

Starter kit for projects built on Containerist. Two pieces, one purpose:

- **`lint.php`** — mechanical linter. Regex-level checks of mod files, stack files, and skin coverage. Fast, runnable in CI.
- **`.claude/skills/containerist-review/SKILL.md`** — Claude Code skill. Runs the linter, then does the semantic review the linter can't: pillar-level drift, cohesion, abstraction creep.

Together they mirror the two levels of the briefing: mechanical rules in the linter (like `docs/containerist.md`), architectural judgment in the skill (like `briefing-detailed.md`).

## What the linter catches

- Mods missing `@in` declarations.
- Mods reading `$_GET`, `$_POST`, `$_SESSION`, `$_SERVER`, `$_FILES`, `STDIN`, or calling `session_start()`, `header()`, `exit`, `die`, `ob_start`. Each breaks realm-independence (pillar 6).
- Variables used inside a mod that are neither declared in `@in` nor assigned locally — best-effort regex, may false-positive on exotic constructs.
- Stack files that don't begin with `CTN: stack`.
- Stack files containing `@if`, `@foreach`, Mustache interpolation, or embedded PHP — any branching violates pillar 2.
- Container names listed in stacks that resolve to neither a mod nor a static container.
- CTN block types emitted anywhere with no matching `skin/{type}.html` template.

## What the linter does not catch

- Semantic misalignment (a mod that passes all mechanical checks but is conceptually a framework-pattern in disguise).
- Pillar-level drift (abstractions growing, cohesion eroding).
- Contract mismatches between stack `@args` and mod `@in`.
- Invented behavior in mods that should be modifiers.

That's what the skill is for.

## Installation

From your project root:

```bash
# Linter
mkdir -p tools
cp path/to/containerist/tools/lint.php tools/

# Claude Code skill
mkdir -p .claude/skills
cp -r path/to/containerist/tools/.claude/skills/containerist-review .claude/skills/
```

Add a `.gitignore` entry for `.claude/skills/` if you want the skill local to your machine only, or commit it so the team (and every Claude Code session) shares it.

## Usage

### Linter (standalone)

```bash
php tools/lint.php                    # lint the current directory
php tools/lint.php path/to/subproject # lint a specific root
```

Exit code `0` = clean or warnings only. Exit code `1` = at least one error. Suitable for pre-commit hooks or CI.

### Claude Code skill

Invoke the skill in a Claude Code session:

- "Run containerist-review on this branch"
- "Review the pond-ingest mod against the pillars"
- "Is this change drifting?"

The skill reads the briefings, runs the linter, and produces a structured report: per-file criteria results, pillar impact table, concrete fixes.

## Customization

Project-specific rules:

- **Forbidden patterns** live at the top of `check_mod()` in `lint.php`. Edit for your project's conventions.
- **Pillar list** in the skill's review checklist. If your project adds a pillar (or relaxes one), update both the skill and the briefing.
- **Skin directory** is hardcoded as `skin/`. If yours lives elsewhere, change the `$skin_dir` config at the top of `lint.php`.

Keep customizations minimal. The briefing is the design authority; these tools enforce it. If you find yourself disabling many checks, the problem is likely that the briefing and the code have diverged — fix the divergence, don't mute the lint.

## Limitations

- The linter is regex-based, not a real PHP parser. It will miss edge cases and occasionally false-positive.
- Variable-tracking is best-effort. If a mod uses `compact()`, dynamic `$$var` indirection, or include-with-scope-sharing, the linter can't follow.
- Skin coverage only checks types explicitly declared in source files via `CTN: <type>` strings. Types assembled dynamically at runtime are invisible to it.

These gaps are acceptable because the Claude skill is the second layer. The linter is fast and catches most common drift; the skill is slower and catches what the linter can't.

## Requirements

- PHP 8.0+ (uses short arrow functions, nullsafe).
- Claude Code (for the skill).
