<p align="center">
  <img src="art/banner.png" alt="Laravel IAM" width="100%">
</p>

<h1 align="center">Laravel IAM — Client</h1>

<p align="center">
  <strong>The drop-in Laravel client for apps that delegate authorization to a Laravel IAM server.</strong><br>
  <code>iam.can</code> / <code>iam.auth</code> middleware, a Gate adapter so <code>$user-&gt;can()</code> just works, decision caching — fail-closed by design.
</p>

<p align="center">
  <a href="https://github.com/padosoft/laravel-iam-client/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/padosoft/laravel-iam-client/tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://packagist.org/packages/padosoft/laravel-iam-client"><img src="https://img.shields.io/packagist/v/padosoft/laravel-iam-client.svg?style=flat-square" alt="Latest Version on Packagist"></a>
  <a href="https://packagist.org/packages/padosoft/laravel-iam-client"><img src="https://img.shields.io/packagist/dt/padosoft/laravel-iam-client.svg?style=flat-square" alt="Total Downloads"></a>
  <a href="https://packagist.org/packages/padosoft/laravel-iam-client"><img src="https://img.shields.io/packagist/php-v/padosoft/laravel-iam-client.svg?style=flat-square" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="License"></a>
</p>

<p align="center">
  <strong><a href="https://doc.laravel-iam-client.padosoft.com">📖 Read the documentation →</a></strong>
</p>

---

## Why this package

[Laravel IAM](https://github.com/padosoft) centralizes **who can do what** into one Policy Decision Point
(PDP): RBAC + ABAC + ReBAC, step-up assurance, tenant isolation, tamper-evident audit. But your *consuming*
apps shouldn't have to learn any of that — they already speak Laravel: middleware, `Gate`, policies,
`$user->can()`.

`laravel-iam-client` is the bridge. You point it at your IAM server, and authorization decisions flow
through the tools your app already uses:

```php
Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])
    ->middleware('iam.can:billing:invoices.update,invoice');   // ← decided by the central PDP
```

It works in two modes with **identical app code**: `local` (the IAM server lives in the same app — the
client calls the PDP in-process, zero network) or `http` (remote server — the client calls the Admin API).
Swap a single env var to move from a modular monolith to a distributed deployment.

> **Fail-closed, always.** Every transport (`LocalDecider`, `HttpDecider`) denies on *any* error —
> unreachable PDP, non-2xx, unparseable body, engine exception. There is no fail-open opt-out: an outage
> never opens the doors.

## Features

- **`iam.can:<permission>` middleware** — a drop-in replacement for Spatie's `permission:` middleware,
  decided by the central PDP. Bind a route parameter (`iam.can:billing:invoices.update,invoice`) and the
  decision is scoped to that resource — including through route-model binding.
- **`iam.auth` middleware** — fail-closed guard that 401s any request without a resolvable subject.
- **Gate adapter** — registers `Gate::before` so `$user->can('billing:invoices.update')`,
  `@can` Blade directives and `authorize()` in controllers all consult IAM. By default it only intercepts
  *namespaced* abilities (those containing `:`), leaving your local Gates/policies untouched.
- **Pluggable transports (`Decider`)** — `LocalDecider` (in-process PDP), `HttpDecider` (remote Admin API),
  both fully fail-closed. Your app code never knows which is in use.
- **`CachingDecider`** — decisions are deterministic per input, so they're cached for a short TTL; `explain`
  queries are never cached.
- **Step-up aware** — a permit that requires a higher assurance level (`requiresStepUp`) is treated as
  *not yet granted* by middleware and the Gate adapter (`IamDecision::granted()`), so you can't accidentally
  let a low-AAL session through.
- **`Iam` facade** — `Iam::can($user, 'warehouse:stock.adjust', ['amount' => 300])` for ABAC checks with
  context.
- **Delegated access (AI agents), act-aware** — verify RFC 8693 delegated tokens (`sub` = user, `act` =
  agent) via mandatory introspection, decide with `Iam::checkDelegated()` / the `iam.can.delegated`
  middleware, and never cache a delegated decision: effective authority is always the fresh strict
  intersection user ∩ agent, revocation included.

## Use cases

- **Protect routes against a central policy.** Replace scattered role checks with
  `->middleware('iam.can:hr:salaries.view')` — the rule lives in IAM, not in your app.
- **Keep using Laravel's authorization API.** `@can`, `$user->can()`, policies and `authorize()` keep
  working; the answer just comes from the central PDP.
- **Per-resource (ReBAC) checks.** `iam.can:projects:edit,project` binds the decision to the bound
  `{project}` — "can this user edit *this* project", not the permission in the abstract.
- **Go from monolith to services without rewriting.** Start with `mode=local` (same app), flip to
  `mode=http` when you extract the IAM server — the controllers don't change.

## Installation

```bash
composer require padosoft/laravel-iam-client
```

Publish the config:

```bash
php artisan vendor:publish --tag=laravel-iam-client-config
```

**Requirements:** PHP **8.3+**, Laravel **11/12+**. Depends on
[`padosoft/laravel-iam-contracts`](https://github.com/padosoft/laravel-iam-contracts).

## Quick start

### 1. Configure the transport

`config/iam-client.php` (publish it, then set env):

```dotenv
# Remote IAM server (use mode=local if the server lives in this same app)
IAM_CLIENT_MODE=http
IAM_CLIENT_BASE_URL=https://iam.example.com/api/iam/v1
IAM_CLIENT_TOKEN=your-service-bearer-token

# Defaults applied to every decision query
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```

That's it — the service provider wires the right decider (with caching) and registers the middleware
aliases and the Gate adapter automatically.

> **Use `https` in production (fail-closed).** Credentials and the Bearer-carrying decision call never travel
> over plain `http://`: a non-`https` `IAM_CLIENT_BASE_URL`/`IAM_CLIENT_OAUTH_URL` (except loopback —
> `localhost`/`127.0.0.1`/`::1`) is **denied** rather than sending a secret or token in clear. Set
> `IAM_CLIENT_ALLOW_INSECURE=true` to lift this for local development only.

#### Authentication modes (choose one)

The SDK authenticates to the PDP in one of three ways (checked in this order of precedence):

- **`private_key_jwt` — asymmetric, no shared secret (strongest)**: give the SDK your `client_id` and an
  **ES256 private key**; it signs a short-lived assertion per token request and exchanges it for an access
  token — nothing secret ever leaves your app, nothing to rotate. Register the matching **public** key (JWKS)
  in IAM. Takes precedence over everything else.

  ```dotenv
  IAM_CLIENT_ID=cli_billing
  IAM_CLIENT_PRIVATE_KEY=/secrets/iam-client.pem     # ES256 PEM (file path or inline contents)
  IAM_CLIENT_PRIVATE_KEY_KID=k1                       # kid of the registered public key
  IAM_CLIENT_OAUTH_URL=https://iam.example.com/oauth  # optional; derived from base_url if omitted
  ```

  Full guide: [private_key_jwt](https://doc.laravel-iam-server.padosoft.com/guides/private-key-jwt).

- **Self-managed `client_credentials`**: give the SDK your OAuth `client_id` + `client_secret` and it **mints
  and refreshes the token itself**, and — when IAM **auto-rotates** the secret — it **self-fetches the new
  one** (during the grace) and hot-swaps it, so the service never breaks on a rotation and you never touch a
  secret by hand.

  ```dotenv
  IAM_CLIENT_ID=cli_billing
  IAM_CLIENT_SECRET=the-secret-iam-issued   # rotatable; the SDK follows rotations automatically
  IAM_CLIENT_OAUTH_URL=https://iam.example.com/oauth   # optional; derived from base_url if omitted
  ```

  The rotated secret is cached (in your cache store) on pickup; enable IAM's self-fetch endpoint server-side
  with `IAM_OAUTH_CLIENT_SELFFETCH=true`. See
  [Application credentials & lifecycle](https://doc.laravel-iam-server.padosoft.com/guides/application-credentials).

- **Static token** (default): you supply a service bearer token via `IAM_CLIENT_TOKEN`, obtained out of band.

### 2. Protect routes with `iam.can`

```php
use Illuminate\Support\Facades\Route;

// Permission only
Route::get('/reports', [ReportController::class, 'index'])
    ->middleware(['auth', 'iam.can:reports:view']);

// Permission bound to a route resource (ReBAC): "can edit THIS invoice"
Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])
    ->middleware(['auth', 'iam.can:billing:invoices.update,invoice']);
```

`iam.auth` ensures there's a resolvable subject (401 otherwise); `iam.can` denies with 403 when IAM says
no — or when a step-up is required but not yet satisfied.

### 3. Use the Gate adapter (your existing code keeps working)

```php
// In a controller
public function update(Request $request, Invoice $invoice)
{
    $this->authorize('billing:invoices.update', $invoice);   // → consults the central PDP
    // ...
}
```

```blade
@can('reports:view')
    <a href="/reports">Reports</a>
@endcan
```

### 4. Ask IAM directly with the facade

```php
use Padosoft\Iam\Client\Facades\Iam;

// ABAC: pass context facts; IAM evaluates the policy
if (Iam::can($user, 'warehouse:stock.adjust', ['amount' => 300, 'resource' => 'wh_milan'])) {
    // approved
}

// Need the full decision (step-up, explanation)?
$decision = Iam::check($user, 'billing:invoices.delete', ['explain' => true]);
$decision->granted();         // permit AND no pending step-up
$decision->requiresStepUp;    // true → ask the user to re-authenticate at a higher AAL
$decision->explanation;       // why (when explain=true)
```

### 5. Declare your permissions/roles: push a manifest

Apps that don't use spatie/laravel-permission **declare** their permission catalog + roles in a manifest file
(versioned in your repo — it *is* your source of truth). Push it to IAM whenever it changes:

```bash
# validate locally against the published schema (any JSON-schema tool works):
#   curl https://your-iam.example.com/.well-known/iam-manifest-schema.json
php artisan iam:manifest:push resources/iam/manifest.json          # app.key comes from the manifest
php artisan iam:manifest:push resources/iam/manifest.json --app=warehouse
```

It submits to IAM's Admin API (authenticated with this client's own bearer — the token needs
`iam:manifests.submit`). IAM diffs it: additive changes apply, a **removal** is gated for approval in the
console and the removed role/permission is **deprecated** (kept for history, disabled), never deleted. Run it
in CI on deploy for hands-off sync. See
[Keeping IAM in sync](https://doc.laravel-iam-server.padosoft.com/guides/keeping-in-sync).

## Delegated access: when an AI agent acts for a user

With the [`laravel-iam-agents`](https://github.com/padosoft/laravel-iam-agents) module on the server, an
agent never receives a user's token: it **exchanges** it (OAuth 2.0 Token Exchange, RFC 8693) for a
short-lived delegated token carrying BOTH identities — `sub` = the user, `act` = the agent. This client is
the enforcement half:

```php
// Inbound: recognize + verify a delegated bearer (introspection-mandatory —
// the authoritative view comes from the server, which also checks the session).
$inspector = app(\Padosoft\Iam\Client\Support\DelegatedBearerInspector::class);
$verifier  = app(\Padosoft\Iam\Client\Auth\DelegatedTokenVerifier::class);

// Decide: BOTH the user and the agent must be allowed — strict intersection, fail-closed.
$decision = Iam::checkDelegated(
    $user,
    ['agent:01J8XKQ0V2'],                                 // the act chain, outermost first
    'orders:read',
    ['delegation_grant_id' => 'dgr_01J9…'],               // pds_dgr claim: revoked grant ⇒ deny, immediately
);

// Or on a route:
Route::get('/orders', ListOrders::class)->middleware('iam.can.delegated:orders:read');
```

Three properties are non-negotiable and built in: a **malformed delegated token throws** (it never degrades
to a single-subject check), **delegated decisions are never cached** (revocation freshness beats the extra
round-trip), and the `typ: delegated+jwt` header is hygiene — the defence is server-side introspection.

This client is also the **exchange half** when your app is itself a registered agent (an orchestrator, a
flow-ai runtime): `TokenExchanger` performs the RFC 8693 call, authenticating with the same
`private_key_jwt` config (`http.client_id` + `http.private_key` — an agent has ONE identity):

```php
use Padosoft\Iam\Contracts\Delegation\{TokenExchanger, TokenExchangeRequest};
use Padosoft\Iam\Client\Auth\TokenExchangeFailedException;

try {
    $delegated = app(TokenExchanger::class)->exchange(new TokenExchangeRequest(
        subjectToken: $userAccessToken,          // the USER's token — never handed to the LLM
        scopes: ['orders:read'],                 // down-scoping (⊆ the grant)
        audience: 'mcp://crm-tools',
    ));
    // $delegated->accessToken: TTL ≤ 300s, non-refreshable — re-exchange IS the revocation check.
} catch (TokenExchangeFailedException $e) {
    // $e->error distinguishes invalid_grant (revoked/suspended/session dead) from invalid_scope
    // (outside the intersection). Never a degraded token: failures throw.
}
```

And every request that passes `iam.can.delegated` hydrates **Laravel Context**: logs emitted downstream
(and queued jobs, via Context's automatic dehydrate/hydrate) carry `iam_delegation` — `sub`, the `act`
chain, the grant id — so cross-package audit queries ("everything agent X did, for anyone") join for free.

## How it fits the ecosystem

| Package | Role |
| --- | --- |
| [laravel-iam-contracts](https://github.com/padosoft/laravel-iam-contracts) | Shared interfaces & DTOs — the dependency root |
| [laravel-iam-server](https://github.com/padosoft/laravel-iam-server) | The IAM server: identity, PDP, OAuth/OIDC, audit, governance, Admin API & panel |
| **laravel-iam-client** *(this repo)* | Consumer SDK: `iam.can`/`iam.auth` middleware, Gate adapter, decision caching |
| [laravel-iam-ai](https://github.com/padosoft/laravel-iam-ai) | Optional AI module: advisory-only governance (redaction + hallucination guard + audit) |
| [laravel-iam-directory](https://github.com/padosoft/laravel-iam-directory) | Optional directory module: LDAP / Active Directory (LdapRecord); SCIM in v2 |
| [laravel-iam-bridge-spatie-permission](https://github.com/padosoft/laravel-iam-bridge-spatie-permission) | Migration bridge from spatie/laravel-permission: scan, shadow mode, decision diffing, cutover |

## Documentation

Full documentation is published at **[doc.laravel-iam-client.padosoft.com](https://doc.laravel-iam-client.padosoft.com)** —
quickstart, guides (`iam.can`, the Gate adapter, the facade, transports, ReBAC, step-up, caching), the
fail-closed theory, architecture + ADRs, and a complete PHP/config reference. The source for that site lives
in [`docs-site/`](docs-site/); a lightweight in-repo copy is under [`docs/`](docs/).

## Security

This client is **fail-closed by design**: any transport error, unreachable PDP, non-2xx response or engine
exception resolves to *deny* — never an allow, never an opaque 500. Step-up-required permits are treated as
not-yet-granted. There is no fail-open switch. If you discover a security issue, please email
**security@padosoft.com** rather than opening a public issue.

## License

MIT © [Padosoft](https://www.padosoft.com). See [LICENSE](LICENSE).
