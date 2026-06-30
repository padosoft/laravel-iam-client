---
title: Migrating from spatie/permission
description: How iam.can maps onto Spatie's permission middleware, where the dedicated migration bridge fits, and why gate.enabled=false during shadow mode.
---

# Migrating from spatie/permission

## The drop-in shape

`iam.can:` is intentionally shaped like Spatie's `permission:` middleware, so the route diff is minimal:

```php
// before — spatie/laravel-permission
Route::get('/reports', ReportController::class)
    ->middleware('permission:reports.view');

// after — laravel-iam-client
Route::get('/reports', ReportController::class)
    ->middleware('iam.can:reports:view');
```

The shape is the same; what changes is **where the rule lives**. With Spatie, `reports.view` is a row in your
app's permission table. With IAM, `reports:view` is declared in the app's manifest on the server, and decided
by the central PDP — with ABAC conditions, ReBAC scoping and step-up that a flat permission table can't
express.

## What you gain

| | spatie/permission | laravel-iam-client |
|---|---|---|
| Where rules live | this app's DB | central PDP (one place for all apps) |
| Model | RBAC | RBAC + ABAC + ReBAC |
| Per-resource checks | manual | `iam.can:perm,routeParam` (route-bound) |
| Assurance / step-up | none | `requiresStepUp` honored by `granted()` |
| Failure mode | local query | fail-closed transport |
| Audit | per-app | tamper-evident, centrally |

## Use the dedicated bridge for a safe cutover

A like-for-like middleware swap is the *end* of a migration, not the whole of it. To get there safely — to
prove the PDP returns the same answers as your Spatie tables *before* you enforce them — use the dedicated
migration package:

::: callout info "laravel-iam-bridge-spatie-permission" icon:replace
[`laravel-iam-bridge-spatie-permission`](https://doc.laravel-iam-bridge-spatie-permission.padosoft.com)
scans your existing roles/permissions, generates a manifest, runs a **shadow mode** that diffs Spatie's
decisions against the PDP's without enforcing, then supports cutover and rollback. It's the recommended path
for anything beyond a trivial app.
:::

## Why `gate.enabled = false` during shadow mode

Shadow mode works by comparing two answers for the same check: what Spatie *would* decide vs what the PDP
decides. If the IAM Gate adapter is also **enforcing** during that window, it changes the observed outcome and
corrupts the diff.

```php
// config/iam-client.php — while the bridge runs in shadow mode
'gate' => [
    'enabled' => false,   // observe & diff only; don't let the adapter enforce
],
```

```mermaid
flowchart LR
    REQ["$user->can(ability)"] --> SHADOW["bridge: compute BOTH answers"]
    SHADOW --> SP["spatie decision"]
    SHADOW --> PDP["PDP decision"]
    SP --> DIFF["diff & log"]
    PDP --> DIFF
    DIFF --> ENF{"gate.enabled?"}
    ENF -->|false (shadow)| OBS["enforce spatie, just observe PDP"]
    ENF -->|true (cutover)| IAMENF["enforce PDP via Gate adapter"]
```

Once the diffs are clean, flip `gate.enabled` back to `true` and let the adapter enforce — that's the cutover.

## Suggested sequence

::: steps
1. **Install the bridge** and generate a manifest from your Spatie data.
2. **Shadow mode** with `iam-client.gate.enabled = false` — collect and resolve decision diffs.
3. **Cut over abilities** by namespacing them and re-enabling the Gate adapter (`gate.enabled = true`,
   `intercept = namespaced`). See [Coexistence](/best-practices/coexistence).
4. **Swap middleware** `permission:` → `iam.can:` on routes as you go.
5. **Decommission** the Spatie tables once every ability is centralized and verified.
:::

## Gotchas

::: callout warning "Permission key format may differ"
Spatie commonly uses dotted keys (`reports.view`); IAM uses namespaced colon keys (`reports:view`). Decide on
the canonical PDP key format and map consistently — the bridge helps, but verify the keys your routes pass
match the manifest.
:::

## See also

- [Coexistence with local Gates](/best-practices/coexistence)
- [Protect routes with iam.can](/guides/protect-routes)
- [Use the Gate adapter](/guides/gate-adapter)
