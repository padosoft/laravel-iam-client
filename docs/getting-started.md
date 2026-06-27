---
title: Getting started
description: Install laravel-iam-client, pick a transport, and protect your first route with iam.can.
---

# Getting started

## Requirements

- PHP **8.3+**
- Laravel **11/12+**
- A reachable Laravel IAM server (or the server installed in the same app for `mode=local`)

## Install

```bash
composer require padosoft/laravel-iam-client
php artisan vendor:publish --tag=laravel-iam-client-config
```

The service provider auto-registers: it builds the right `Decider` from config, wraps it in the cache,
registers the `iam.can` / `iam.auth` middleware aliases (only if not already taken) and the Gate adapter.

## Configure the transport

::: tabs
== tab "Remote server (http)" icon:cloud
```dotenv
IAM_CLIENT_MODE=http
IAM_CLIENT_BASE_URL=https://iam.example.com/api/iam/v1
IAM_CLIENT_TOKEN=your-service-bearer-token
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
== tab "Same app (local)" icon:server
```dotenv
IAM_CLIENT_MODE=local
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
In `local` mode the client resolves the server's `AuthorizationEngine` from the container and calls the PDP
in-process — no network.
:::

## Protect your first route

::: steps
1. **Add the middleware**
   ```php
   Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])
       ->middleware(['auth', 'iam.can:billing:invoices.update,invoice']);
   ```
2. **Let the central PDP decide**
   `iam.auth` 401s if there's no subject; `iam.can` 403s when IAM denies (or a step-up is required). The
   `,invoice` segment binds the decision to the route's `{invoice}` (ReBAC).
3. **Keep your Laravel code**
   `$this->authorize('billing:invoices.update', $invoice)` and `@can('billing:invoices.update')` now consult
   IAM through the Gate adapter — no rewrite.
:::

## Verify

```php
use Padosoft\Iam\Client\Facades\Iam;

Iam::can($user, 'billing:invoices.update', ['resource' => $invoice->id]);  // true / false
Iam::check($user, 'billing:invoices.delete', ['explain' => true])->explanation;  // why
```

::: callout warning "Fail-closed by default"
If the IAM server is unreachable, every decision is **deny**. That's intentional — there is no fail-open
switch. Plan your deployment (caching, local mode, server HA) accordingly.
:::
