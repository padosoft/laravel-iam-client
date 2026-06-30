---
title: Ask IAM with the facade
description: Use the Iam facade and IamClient for imperative checks — can, denies, check — with ABAC context, reserved keys, and explanations.
---

# Ask IAM with the facade

## Motivation

Middleware and the Gate adapter cover declarative authorization. Sometimes you need an **imperative** check
inside a service, a job, or a branch — *"is this user allowed to adjust 300 units of stock in Milan?"*. The
`Iam` facade (backed by `IamClient`) is that entry point.

## The three methods

```php
use Padosoft\Iam\Client\Facades\Iam;

Iam::can($user, 'reports:view'): bool;                 // granted? (step-up aware)
Iam::denies($user, 'reports:view'): bool;              // !can(...)
Iam::check($user, 'reports:view'): IamDecision;        // the full decision object
```

- **`can()`** returns `true` only when the decision is [`granted()`](/concepts/granted-vs-allowed) — permit
  *and* no pending step-up.
- **`denies()`** is exactly `!can(...)`.
- **`check()`** returns the whole [`IamDecision`](/concepts/decision-contract): `allowed`, `requiresStepUp`,
  `requiredAal`, `decisionId`, `policyVersion`, `explanation`.

## Subjects

The first argument is `Authenticatable | string | null`:

| You pass | Subject id used |
|---|---|
| an `Authenticatable` | `$user->getAuthIdentifier()` (cast to string, if scalar) |
| a `string` | the string itself (e.g. `'42'`) |
| `null` | none → the decision is `deny('no-subject')` |

```php
Iam::can($request->user(), 'billing:invoices.view');  // typical
Iam::can('42', 'billing:invoices.view');               // pass a raw subject id
```

## ABAC context and reserved keys

The `$context` array does double duty. A handful of **reserved keys** are pulled out and mapped onto the
query; everything else is sent to the PDP as ABAC facts.

```php
$decision = Iam::check($user, 'warehouse:stock.adjust', [
    // reserved → become query fields
    'organization' => 'org_acme',
    'application'  => 'warehouse',
    'resource'     => 'wh_milan',
    'aal'          => 'aal2',
    'explain'      => true,

    // not reserved → ABAC facts the PDP evaluates
    'amount'       => 300,
    'shift'        => 'night',
]);
```

| Reserved key | Effect | Default if absent |
|---|---|---|
| `organization` | tenant for the query | `default_organization` config |
| `application` | app namespace | `default_application` config |
| `resource` | ReBAC resource reference | none |
| `aal` | current assurance level | `aal1` |
| `explain` | request a human-readable explanation | `false` |

See [ABAC context & ReBAC resources](/concepts/context-and-resources) for the full model.

## Worked example — an ABAC-gated action

```php
use Padosoft\Iam\Client\Facades\Iam;

public function adjustStock(Request $request, Warehouse $warehouse)
{
    $amount = (int) $request->input('amount');

    $decision = Iam::check($request->user(), 'warehouse:stock.adjust', [
        'resource' => (string) $warehouse->getKey(),
        'amount'   => $amount,
    ]);

    if ($decision->requiresStepUp) {
        // permitted, but only at a higher AAL — drive a re-auth flow
        return redirect()->route('auth.step-up', ['return' => url()->current()]);
    }

    if (! $decision->granted()) {
        abort(403);
    }

    // ... perform the adjustment
}
```

## Explanations

Pass `'explain' => true` to get `IamDecision::$explanation` (a list of strings) describing *why*. Use it for
debugging, audit annotations, or surfacing a reason to an admin UI.

::: callout warning "Explanations are never cached"
The [`CachingDecider`](/guides/cache-decisions) bypasses the cache whenever `explain` is set, so an explained
decision is always computed fresh and never shared across contexts. Don't put `explain => true` on a hot path
expecting cache hits.
:::

## Gotchas

::: callout danger "Gate on can()/granted(), not allowed"
`Iam::check(...)->allowed` can be `true` while the action is still blocked pending step-up. For a yes/no
gate, use `Iam::can()` (or `->granted()`). Reach for `allowed` / `requiresStepUp` only when you intend to
*handle* the step-up yourself.
:::

## See also

- [Handle step-up assurance](/guides/step-up)
- [PHP API reference](/reference/php-api) — full signatures.
- [The decision contract](/concepts/decision-contract)
