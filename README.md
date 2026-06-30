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
