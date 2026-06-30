---
title: Quickstart
description: Install laravel-iam-client, pick a transport, protect a route with iam.can, and verify a decision — end to end in a few minutes.
---

# Quickstart

This page takes you from `composer require` to a working, centrally-decided route. For the why behind each
step, follow the links into [Core concepts](/core-concepts) and the guides.

## 1. Install

```bash
composer require padosoft/laravel-iam-client
php artisan vendor:publish --tag=laravel-iam-client-config
```

The service provider (`IamClientServiceProvider`) auto-registers on boot. It:

- builds the right [`Decider`](/architecture/transports) from `iam-client.mode` and wraps it in the cache;
- registers the `iam.can` and `iam.auth` middleware aliases — **only if those names aren't already taken**;
- registers the [Gate adapter](/guides/gate-adapter) when `gate.enabled` is `true`.

## 2. Choose a transport

::: tabs
== tab "Remote server (http)" icon:cloud
```dotenv
IAM_CLIENT_MODE=http
IAM_CLIENT_BASE_URL=https://iam.example.com/api/iam/v1
IAM_CLIENT_TOKEN=your-service-bearer-token
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
The client `POST`s each query to `{IAM_CLIENT_BASE_URL}/decisions/check` with a Bearer token.
== tab "Same app (local)" icon:server
```dotenv
IAM_CLIENT_MODE=local
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
In `local` mode the client resolves the server's `AuthorizationEngine` from the container and calls the PDP
in-process — no network round-trip.
:::

`IAM_CLIENT_APP` and `IAM_CLIENT_ORG` become the default `application` and `organization` on every query, so
you don't repeat them at each call site. See [Configuration](/operations/configuration).

## 3. Protect a route

::: steps
1. **Add the middleware**
   ```php
   use Illuminate\Support\Facades\Route;

   // Permission only
   Route::get('/reports', [ReportController::class, 'index'])
       ->middleware(['auth', 'iam.can:reports:view']);

   // Permission bound to a route resource (ReBAC): "can edit THIS invoice"
   Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])
       ->middleware(['auth', 'iam.can:billing:invoices.update,invoice']);
   ```
2. **Let the central PDP decide**
   `iam.auth` returns **401** when there's no resolvable subject; `iam.can` returns **403** when IAM denies —
   or when a step-up is required but not yet satisfied. The `,invoice` segment binds the decision to the
   route's `{invoice}` (including through route-model binding).
3. **Keep your Laravel code**
   In a controller, `$this->authorize('billing:invoices.update', $invoice)` and `@can('reports:view')` in a
   Blade view now consult IAM automatically — through the [Gate adapter](/guides/gate-adapter), no rewrite.
:::

## 4. Ask IAM directly

```php
use Padosoft\Iam\Client\Facades\Iam;

// ABAC: pass context facts; IAM evaluates the policy
if (Iam::can($user, 'warehouse:stock.adjust', ['amount' => 300, 'resource' => 'wh_milan'])) {
    // approved
}

// Need the full decision (step-up, explanation)?
$decision = Iam::check($user, 'billing:invoices.delete', ['explain' => true]);
$decision->granted();         // permit AND no pending step-up  ← gate on this
$decision->requiresStepUp;    // true → ask the user to re-authenticate at a higher AAL
$decision->explanation;       // why (when explain=true)
```

## 5. Verify

::: callout tip "Smoke test in tinker"
```bash
php artisan tinker
```
```php
>>> $u = \App\Models\User::first();
>>> \Padosoft\Iam\Client\Facades\Iam::can($u, 'reports:view');   // true / false
>>> \Padosoft\Iam\Client\Facades\Iam::check($u, 'billing:invoices.delete', ['explain' => true])->explanation;
```
:::

::: callout warning "Fail-closed by default"
If the IAM server is unreachable, every decision is **deny** — by design. There is no fail-open switch.
Plan your deployment (caching, `local` mode, server HA) accordingly. See
[Fail-closed authorization](/concepts/fail-closed).
:::

## Where to next

::: grids
  ::: grid
    ::: card "Core concepts" icon:square-function
    The mental model: deciders, the decision contract, `granted()` vs `allowed`.
    [Open →](/core-concepts)
    :::
  :::
  ::: grid
    ::: card "Protect routes" icon:shield
    Everything `iam.can` and `iam.auth` can do, with worked examples.
    [Open →](/guides/protect-routes)
    :::
  :::
  ::: grid
    ::: card "Choose a transport" icon:arrow-left-right
    `local` vs `http`: trade-offs, topology, and how to switch with one env var.
    [Open →](/guides/choose-transport)
    :::
  :::
:::
