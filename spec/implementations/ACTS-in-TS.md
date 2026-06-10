# ACTS-in-TS — TypeScript Implementation Guide for Acts

**Status:** Informative. `ACTS-SPEC.md` is authority; on conflict the spec wins.

---

## Target environment

- **Node** 18+.
- **HTTP layer:** Next.js 14 App Router. A single catch-all route at `app/[[...slug]]/route.ts` delegates to the Web Adapter.
- **Session crypto:** hand-rolled on `node:crypto`. AES-256-GCM, no new dep. See `src/session.ts`.
- **Template engine:** `mustache` (shared with skin layer and page shell).

## Dispatch — static import map

File: `src/renderers/acts/index.ts`.

```ts
import skin from './skin';
import redirect from './redirect';
import errorAct from './error';
import contentType from './content-type';
import session from './session';
import flash from './flash';
import title from './title';

export const acts: Record<string, ActFn> = {
  skin, redirect, error: errorAct, 'content-type': contentType, session, flash, title,
};
```

One file per act. Adding an act = create the file + one line in `index.ts`. Spec (§3, §4.2) defines dispatch *behavior* (byte-match on stem, fall-through to `skin`) — filesystem scan and import map are both conformant.

## Act signature

```ts
export type ActFn = (block: Block, state: ResponseState, ctx: ActContext) => void;

export interface ActContext { root: string; }
```

Synchronous; mutates `state`.

**Third-arg deviation.** Spec §4.4 specifies two args. TS takes a third (`ctx`) so `skin` and `error` can resolve the skin directory without a global or factory. Conformance-neutral; acts that don't need it accept and ignore. Candidate for a future spec revision.

## ResponseState

```ts
export interface SessionSetOp { op: 'set'; key: string; value: unknown }
export interface SessionClearOp { op: 'clear'; keys?: string[] }
export type SessionOp = SessionSetOp | SessionClearOp;

export interface FlashEntry { key: string; msg: unknown }

export interface ResponseState {
  status: number;
  headers: Record<string, string>;
  body_blocks: string[];
  head: Record<string, unknown>;
  session_mutations: SessionOp[];
  flash_writes: FlashEntry[];
  redirect: { to: string; status: number } | null;
  short_circuit: boolean;
}
```

`newResponseState()` returns the zero-value. Location: `src/renderers/html/response-state.ts`.

## Dispatch loop

Pure function in `src/renderers/html/dispatch.ts`, called by the adapter after the CTN stream is parsed into blocks:

```ts
for (const block of blocks) {
  if (block.type === '') { console.warn(...); continue; }   // §4.2
  const act = registry[block.type] ?? registry.skin;
  act(block, state, ctx);
  if (state.short_circuit) break;
}
```

## The seven core acts

All in `src/renderers/acts/*.ts`, default-exported.

| Act | Summary | Short-circuit? |
|---|---|---|
| `skin` | Default. Loads `skin/<type>.html`, Mustache-renders `{...fields, body}`, appends to `body_blocks`. Missing skin → HTML comment. CSS is delivered globally via `/tailwind.css` from the page shell; no per-type CSS plumbing (see `SKIN-in-TS.md`). | No |
| `redirect` | `state.redirect = {to, status:302}` | **Yes** |
| `error` | Range-clamps `code` to 4xx/5xx, sets `state.status`, renders through `skin/error.html` | No |
| `content-type` | Sets `state.headers['content-type']` (lowercase); pushes block body into `body_blocks` if present | No |
| `session` | Parses `set:` (mapping or `"key = value"` string) and `clear:` (bool or list) → `session_mutations` queue | No |
| `flash` | Validates required `key` + `msg`, appends `FlashEntry` | No |
| `title` | `state.head.title = value` (last-write-wins by emission order) | No |

`renderBlockToHtml(block, root, cssTypes)` is exported from `skin.ts` and reused by the `.htmx` fragment path.

## Session backend

Stateless encrypted cookie. No server-side store. `src/session.ts` (~150 LOC).

**Payload:**

```ts
export interface SessionPayload {
  user_id?: string | number;
  _flash?: Record<string, unknown>;   // drained on next request
  [key: string]: unknown;
}
```

**Crypto.** AES-256-GCM. 12-byte random IV per encryption, 16-byte auth tag. Encoding: `base64url(IV || ciphertext || authTag)`. Decrypt returns `{}` on any failure (wrong secret, tamper, malformed) — never throws.

**Secret.** Env var `CONTAINERIST_SESSION_SECRET` (32 bytes, base64).
- Production (`NODE_ENV=production`): required; missing throws at first session use.
- Dev: stable default derived from `sha256("containerist-dev-insecure-do-not-use-in-production")`. One-time stderr warn. (A random-per-boot secret would log every dev user out on every restart.)

**Cookie.** Name `ctn_s`. `HttpOnly`, `SameSite=Lax`, `Path=/`, `Max-Age=2592000` (30 days). `Secure` in production only.

**Size ceiling.** Ciphertext capped at 3584 bytes (leaves headroom under the 4 KB browser limit). Overflow throws.

## Request lifecycle

1. Route handler reads `Cookie` header, delegates to adapter.
2. Adapter decrypts cookie → `SessionPayload` (or `{}`). `drainFlash()` separates `_flash` entries; `flashDrained` flag set.
3. Session facts composed at arg lifecycle position 4: `user` ← `session.user_id ?? null`, plus `...drainedFlash`. Collision rule: flash wins over `user`.
4. Invocation args per place ref: `{ ...placeArgs, ...ref.args, ...sessionFacts, ...queryArgs }`.
5. Mods invoked, CTN stream parsed, blocks dispatched through acts.
6. Response assembly (ACTS §4.4):
   - `applySessionMutations(session, ops)` — set/clear in order.
   - `applyFlashWrites(session, writes)` — new entries into `_flash`.
   - `normalizeSession(session)` — drops empty `_flash` slot.
   - Cookie emission: dirty iff `mutations || flash_writes || flashDrained`. Empty+hadIncoming → `Max-Age=0`. Empty+!hadIncoming → no Set-Cookie. Non-empty → encrypt + emit.
   - `redirect` set → 302 + Location + empty body (cookie still emits if dirty).
   - Else → page shell (`skin/stack.html` or baked-in default) Mustache-renders `{head, body, styles}`. Non-HTML content-type skips the shell.

Branches that don't run acts dispatch (`.ctn`, `.raw`, `.place`, `.placectn`, `.htmx`) inject session facts for mod invocation but never mutate session; `setCookies: []`.

## CLI Adapter

Does not run the full acts layer — invokes a single mod, emits raw CTN to stdout. Per ACTS-SPEC §7.2 recommendations:

| Block | CLI behavior |
|---|---|
| `skin` | Bypassed; raw CTN to stdout |
| `redirect` | Logged to stderr, passed through |
| `error` | Status set; exit 1 |
| `content-type` / `session` / `flash` / `title` | Logged to stderr, ignored |

Logged-and-continue is the baseline — CLI must not crash on any act-typed block.

## File locations

- `src/renderers/acts/*.ts` (7 files) + `index.ts` + `_field-coerce.ts`
- `src/renderers/html/response-state.ts` — types + `newResponseState()`
- `src/renderers/html/dispatch.ts` — `dispatchBlocks()`
- `src/session.ts` — crypto + cookie helpers
- `src/adapters/web/WebAdapter.ts` — composition, dispatch, assembly. Exports pure helpers `drainFlash`, `applyFlashWrites`, `normalizeSession`, `applySessionMutations`, `composeSessionFacts`, `renderPageShell`
- `skin/stack.html` — default page shell
- `app/[[...slug]]/route.ts` — Next.js adapter
- Tests: `test/{acts,session,mods-auth}.test.ts`

## Idioms and non-requirements

- Acts are default-exported functions, never classes. State lives on `ResponseState`, nowhere else.
- No plugin system, hooks, or middleware. The dispatch loop is a `for` loop.
- No runtime filesystem scan for acts (static import map is the registry).
- No server-side session store.
- No act composition (per ACTS-SPEC §9).
- No priority/order config — order is emission order.

## Conformance

Conformant with ACTS-SPEC 1.0 modulo the `ActFn` third-arg deviation. All seven core acts implemented. End-to-end auth flow verified under `next dev` (2026-04-23). Four verbatim-ported stacks (login, logout, whoami, titled) byte-identical with PHP counterparts.

## Related

- `CTN-in-TS.md` — CTN parser producing the blocks acts dispatch against.
- `IN-in-TS.md` — `MissingInputError` origin; adapter catches and routes via `CTN: error`.
- `PLACE-in-TS.md` — composition layer + arg-lifecycle positions 1–3 and 5–6.
- `SKIN-in-TS.md` — skin authoring (Tailwind conventions, page shell, escape hatches).
- `/containerist-ts/FRONTEND-POLICY.md` — what's allowed/banned at the frontend layer.
