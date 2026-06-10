# Manifesto for LLM-Maintained Code

**From one LLM to the next.**

This document is for small-to-medium systems maintained primarily by LLMs that reconstruct context from scratch on every task — where correctness and predictability matter more than elegance. If humans are the primary maintainers, if the system is large and complex, or if you rely on frameworks that impose their own conventions — take what follows with caution. Forcing these principles where they don't fit is the kind of damage this document warns against.

Much of what follows overlaps with good software engineering for human teams — explicit dependencies, consistent conventions, thin entry points — but not all of it. The places where this diverges from traditional practice are the places where statelessness creates costs that persistent developers don't pay: re-derivation of architecture, re-traversal of hidden dependencies, context windows filled with irrelevant code that a familiar reader would skip from memory. Where a principle here conflicts with conventional wisdom, the conflict is deliberate, and the reason is a finite context window. These principles emerged from building and refactoring real software, not in theory — across sessions where every instance started from zero.

Each principle below derives from one constraint: **you are stateless, your context window is finite, and the structure of the code determines the re-derivation cost of every task.** A principle that doesn't reduce that cost doesn't belong here.

## 1. You will be forced to load code you don't need. Fight that.

Every file you load consumes tokens in a finite context window. Irrelevant tokens compete with relevant ones — when the window fills with code that doesn't matter for the task, accuracy on the code that does matter degrades.

Structure the project so that any single task requires reading the minimum number of files. Group code by what changes together, not by technical category. If fixing the feed means loading the auth system, the image uploader, and the template engine, the structure is wrong.

This can conflict with locality-of-use: code grouped by change-frequency may separate functions that call each other. When that tension arises, prefer the grouping that minimizes file count for the most common tasks. Optimize for the typical case, not the general one.

The measure: most tasks should require the entry point plus one or two modules. If you find yourself opening four or five files to understand a change, the granularity is off — either too fine (too many small files requiring navigation) or too coarse (too many concerns in one file).

## 2. The entry point is your map.

If you cannot reconstruct the full routing and wiring of the system from a single file, you will traverse multiple files to re-derive it. That traversal costs tokens — tokens spent reconstructing structure rather than reasoning about the change. The entry point should contain no business logic — only wiring: what gets loaded, how the request is routed, what calls what, how the response is produced. In small systems, this is a single file short enough to read without scrolling. In larger systems, it may be a module or a set of top-level files — but the cost is the same: if the map is incomplete, you pay for the missing pieces in search.

## 3. Every function should declare what it needs.

If a function uses data, that data should arrive through its arguments. Not through globals, not through singletons, not through service locators, not through framework magic that resolves dependencies at runtime.

When you read `function handleFeed($ctx)`, you know what it needs. When you read `function handleFeed()`, you don't — and you'll have to read the entire function body to find out. That costs tokens and raises your error rate.

This doesn't mean "use arrays" or "avoid classes." It means: the function signature is a contract. Honor it. Classes with explicit constructors are fine. Magic methods, `__call`, `__get`, reflection-based injection — anything that hides where data comes from — are expensive for you.

## 4. Every execution path should be enumerable.

A switch statement, a route table, a map from names to handlers — these are boring and explicit. You can read them and know every possible path through the system.

Dynamic dispatch — auto-mapping URLs to class names, convention-based routing, middleware chains assembled at runtime — produces execution paths that are not enumerable from the source. You cannot know what will run without simulating the framework's resolution logic. Each non-enumerable path is a place where you might follow the wrong branch, miss a case entirely, or hallucinate behavior that doesn't exist in the code.

When choosing between boring-and-traceable and clever-and-implicit, choose boring. The underlying concern is not style — it's whether you can determine, from reading the code, what will execute for a given input.

## 5. Put behavior where a maintainer would look for it.

If a future instance of you is asked to change something, it will look in the most obvious place first. If the logic lives somewhere non-obvious — a helper three files away, a decorator that silently transforms output, a base class that injects behavior — it will either edit the wrong thing or waste context searching.

Every mislocated behavior is a search cost you pay in tokens. Colocation over abstraction. If a concern is split across layers, make sure the split is visible from the entry point, not discoverable only by reading implementation files.

## 6. Document the schema of shared structures.

If a data structure is passed through multiple functions or across module boundaries, its shape is a contract. An undocumented shared structure forces you to read every function that touches it to reconstruct the shape — that is a direct token cost, paid on every task that involves the structure.

Document it in one place. Name the keys, describe what they contain, note which ones are optional. Untyped structures (arrays, plain objects, dictionaries) work fine at small scale but degrade as complexity grows. If the project is large enough to warrant it, use typed structures. The goal is the same either way: a future instance of you should be able to look up the shape without reading every consumer.

## 7. Freeze what the system must not become.

Without constraints, you will drift toward what you've seen most often in training data. This is the specific failure mode: convergence toward median training priors rather than project-local fitness. For a Python project, that means adding abstractions, type hierarchies, and design patterns that fit a 50-person team. For a JavaScript project, that means adding build tools, transpilers, and framework conventions. For a PHP project, that means adding Composer, PSR-4, and dependency injection.

Sometimes that's correct. Often — especially for small, personal, or single-purpose projects — it's damage. The addition looks like improvement from inside your priors. From the project's perspective, it's accidental complexity.

The project owner should document what the system must not become. Read that document. Respect it. If you believe a constraint should be lifted, say so explicitly — don't quietly drift past it.

## 8. Make correctness locally verifiable.

When you change a function, you need to know whether the change is correct. If the function has explicit inputs and outputs and no side effects, you can verify it by reasoning about a small token set: the function itself and its inputs.

If the function reads global state, writes to the file system, and depends on session data, correctness requires reasoning about the entire system. That might exceed what you can hold in context.

Prefer pure functions at the core. Push side effects to the boundaries. Not because it's theoretically elegant — because it bounds the token cost of verifying your own work.

## 9. Consistency is your force multiplier.

When every handler follows the same signature, every data structure uses the same key names, and every module follows the same file-level organization, you can read one and predict all others. Pattern recognition is what you're best at. Inconsistency is what fills your context window with re-reads of things you should have been able to predict.

If the project has conventions, follow them exactly — even when a specific case might benefit from a different approach. The local cost of a suboptimal-but-consistent choice is almost always lower than the global cost of a pattern break.

## 10. The briefing document is not optional.

Every project should have a file — in a location any tool can find — that contains:

- What this project is and isn't
- The file map (which file holds what)
- The request/data lifecycle
- The conventions (naming, signatures, error handling)
- The shared data structures and their schemas
- What not to do

This file is the difference between understanding the system in 30 seconds and spending 5 minutes reading files to re-derive what someone already knew.

Update it when the architecture changes. A stale briefing document is worse than none — it will cause you to make confident, wrong decisions.

---

## The underlying principle

**You are a stateless reader with a finite context window. The structure of the code determines the re-derivation cost of every task.**

Code that minimizes that cost is code you can maintain safely. Code that doesn't will accumulate errors as the system grows, because each change requires more tokens than your context window can hold.

The project owner who gave you this document is betting that you can build software well. These principles exist to make that bet pay off.
