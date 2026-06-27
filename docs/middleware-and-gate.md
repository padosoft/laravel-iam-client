---
title: Middleware & Gate
description: Protect routes with iam.can and iam.auth, and wire the central PDP into Laravel's Gate.
---

# Middleware & Gate

## `iam.auth`

Ensures the request has a resolvable subject. It does **not** replace Laravel's `auth` guard — it assumes
it and fails closed (401) if no user is present.

```php
Route::middleware(['auth', 'iam.auth'])->group(function () {
    // every route here has a subject IAM can reason about
});
```

## `iam.can:<permission>[,<routeParam>]`

A drop-in replacement for Spatie's `permission:` middleware, decided by the central PDP.

```php
// Permission only
->middleware('iam.can:reports:view');

// Permission bound to a route resource (ReBAC)
->middleware('iam.can:billing:invoices.update,invoice');
```

- **401** when there's no authenticated user.
- **403** when IAM denies — or when the permit requires a step-up that isn't satisfied yet.
- With a `routeParam`, the bound value (including a route-model-bound Eloquent model) becomes the decision's
  `resource`.

::: callout warning "Resource binding and over-authorization"
If you write a per-resource permission but forget the `,routeParam`, the check is evaluated *globally* —
which may grant more than intended. When a permission is about "this specific thing", always bind the route
parameter.
:::

## The Gate adapter

Registered automatically (toggle with `iam-client.gate.enabled`). It hooks `Gate::before` and delegates
**namespaced** abilities (those containing `:`) to IAM, returning `null` for the rest so your local
Gates/policies keep working.

```php
// All of these now consult the central PDP for "billing:*" abilities:
$user->can('billing:invoices.update', $invoice);
$this->authorize('billing:invoices.update', $invoice);
```
```blade
@can('billing:invoices.update', $invoice)
    <button>Edit</button>
@endcan
```

`intercept` controls the scope:

- `namespaced` (default) — only abilities with `:` go to IAM; everything else is left to Laravel.
- `all` — every ability is delegated to IAM.

::: callout tip "Coexistence with local policies"
Keep `intercept: namespaced` while you migrate. Your existing `UserPolicy`, `PostPolicy`, etc. keep
running for non-namespaced abilities; only `app:permission`-style abilities are centralized.
:::

## Shadow mode

When running the [migration bridge](https://github.com/padosoft/laravel-iam-bridge-spatie-permission) in
shadow mode, set `iam-client.gate.enabled = false` so the Gate adapter's enforcement doesn't corrupt the
shadow diffing.
