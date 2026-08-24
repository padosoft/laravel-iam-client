---
title: Delegated access (AI agents)
description: Verify RFC 8693 delegated tokens carrying two identities (sub = user, act = agent), decide with checkDelegated on the strict user ∩ agent intersection, and protect agent-facing routes with iam.can.delegated.
---

# Delegated access (AI agents)

## Motivation

When an AI agent acts *on behalf of* a user, the worst design is also the most common one: handing the
agent the user's own token. That token grants everything the user can do, for as long as it lives, with no
way to tell "the user did this" from "the agent did this".

With the [`laravel-iam-agents`](https://github.com/padosoft/laravel-iam-agents) module on your IAM server,
the agent instead **exchanges** the user's token (OAuth 2.0 Token Exchange, [RFC 8693](https://datatracker.ietf.org/doc/html/rfc8693))
for a **short-lived, non-refreshable delegated token** that carries BOTH identities:

```json
{ "sub": "user:42", "act": { "sub": "agent:01J8XKQ0V2" },
  "scope": "orders:read", "pds_dgr": "dgr_01J9…", "typ": "delegated+jwt" }
```

This client is the **enforcement half** (the PEP): it recognizes such tokens inbound, verifies them the
only safe way, and decides on the **strict intersection** — the action passes only if *both* the user
*and* the agent are allowed. Never the union, always fail-closed.

## Inbound: recognize and verify

Two collaborators, both container-resolved:

- **`Support\DelegatedBearerInspector`** — detects a delegated bearer by its `typ: delegated+jwt` header
  *or* an `act` claim. A **malformed delegated token throws** — it never silently degrades into a
  single-subject token (that would let an agent impersonate its user on a legacy code path).
- **`Auth\DelegatedTokenVerifier`** — **introspection-mandatory**: the authoritative view of a delegated
  token comes from the server's `/oauth/introspect`, which also checks that the delegating user's session
  is still alive. Local claim parsing is only a hint; the server is the truth.

::: callout warning "Why introspection is mandatory" icon:shield-alert
A resource server that trusts local signature checks alone will keep honouring a delegated token for its
whole TTL even after the user revoked the grant or logged out. Introspection makes revocation land at the
**next request**, not at token expiry.
:::

## Decide: the intersection rule

```php
use Padosoft\Iam\Client\Facades\Iam;

$decision = Iam::checkDelegated(
    $user,                                    // the delegating subject (sub)
    ['agent:01J8XKQ0V2'],                     // the act chain, outermost actor first
    'orders:read',
    ['delegation_grant_id' => 'dgr_01J9…'],   // from the token's pds_dgr claim
);

if ($decision->granted()) {
    // both the user AND the agent are allowed — and the grant is still active
}
```

Under the hood the request reaches the PDP's `checkDelegated`: user check **∧** agent check **∧** grant
still active. A suspended agent, a revoked grant, or a user-side deny each veto the whole decision
(deny-overrides on every layer). `Iam::canDelegated(...)` is the boolean shorthand.

## Protect an agent-facing route

```php
Route::get('/orders', ListOrders::class)
    ->middleware('iam.can.delegated:app:orders.read');
```

The `iam.can.delegated` middleware:

1. requires a bearer, verifies it as **delegated** (a plain user token here is a `401` — human routes use
   `iam.can`, agent routes use this; never ambiguity);
2. decides on the intersection, threading the token's `pds_dgr` as `delegation_grant_id`;
3. on pass, exposes the delegation to your controller **and hydrates Laravel Context**:

```php
$delegation = $request->attributes->get('iam_delegation');
// ['sub' => 'user:42', 'actors' => ['agent:01J8XKQ0V2'], 'grant_id' => 'dgr_…', 'scopes' => [...]]

use Illuminate\Support\Facades\Context;
Context::get('iam_delegation'); // same shape — automatically attached to every log entry
```

Because it rides [Laravel Context](https://laravel.com/docs/context), the delegation follows the request
into **every log line and every queued job** (Context dehydrates/rehydrates itself) — no package
downstream needs to know delegation exists, yet the audit pivot queries ("everything agent X did, for
anyone") join across packages on these fields for free.

## Outbound: performing the exchange (when your app IS the agent)

The same package covers the client side of RFC 8693 for orchestrators and agent runtimes (e.g. flow-ai's
`DelegatedIdentityResolver`): `TokenExchanger` — resolved from the container, authenticated with the same
`private_key_jwt` settings (`http.client_id` + `http.private_key`: an agent has **one** identity).

```php
use Padosoft\Iam\Client\Auth\TokenExchangeFailedException;
use Padosoft\Iam\Contracts\Delegation\TokenExchanger;
use Padosoft\Iam\Contracts\Delegation\TokenExchangeRequest;

try {
    $delegated = app(TokenExchanger::class)->exchange(new TokenExchangeRequest(
        subjectToken: $userAccessToken,   // the USER's token — held by the backend, never by the LLM
        scopes: ['orders:read'],          // down-scoping (must be ⊆ the delegation grant)
        audience: 'mcp://crm-tools',      // recommended for MCP tool servers (aud-scoped tokens)
    ));
    $delegated->accessToken;              // TTL ≤ 300s, non-refreshable
} catch (TokenExchangeFailedException $e) {
    $e->error; // 'invalid_grant' (revoked / agent suspended / session dead) vs 'invalid_scope'
}
```

Deliberately **no caching and no refresh**: the re-exchange *is* the revocation freshness check — the
server re-verifies grant and user session on every call. Failures **throw** (`$e->error` carries the RFC
error code); a degraded or empty token is never returned. The exchange refuses insecure transport before
sending anything: the subject token and the signed assertion never travel in cleartext.

## Delegated decisions are never cached

`CachingDecider` bypasses its cache whenever the request carries an actor chain. A delegated decision must
observe a revocation **immediately** — at agent volumes the extra round-trip is the price of the
kill-switch actually working. Plain user decisions keep their short-TTL cache, unchanged.

## The three built-in guarantees

| Guarantee | Why it matters |
| --- | --- |
| Malformed delegated token **throws** | No downgrade path from "agent acting for user" to "just the user" |
| **Introspection-mandatory** verification | Revocation lands at the next request, session liveness included |
| Delegated decisions **never cached** | The admin/user kill-switch is real, not eventually-consistent |

## See also

- [`laravel-iam-agents`](https://github.com/padosoft/laravel-iam-agents) — the server module: agent
  registry, token exchange, delegation grants, PSD2-grade consent, delegation audit stream.
- [Cache decisions](/guides/cache-decisions) — why normal decisions cache and delegated ones don't.
- [Fail-closed authorization](/concepts/fail-closed) — the posture every piece above inherits.
