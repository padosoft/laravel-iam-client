---
title: Fail-closed by design
description: Operational practices for living with a fail-closed authorization client — availability planning, caching, step-up handling, and what NOT to do.
---

# Fail-closed by design

The client's [fail-closed guarantee](/concepts/fail-closed) is a property you build *around*, not against.
This page is the operational checklist.

## Do

::: callout success "Plan availability at the topology layer" icon:server
A denied action during a PDP outage is correct behavior, not a bug to patch in the client. Buy availability
where it belongs:

- prefer **`local` mode** when the server can live in the same app (a function call can't time out across a
  network);
- run the remote server **highly available** (multiple nodes, health checks);
- set a sensible **cache TTL** so transient blips are absorbed by recent decisions.
:::

::: callout success "Gate on granted(), surface step-up" icon:shield-check
Use `Iam::can()` / `->granted()` for the yes/no decision. Where a sensitive action is worth a re-auth prompt,
read `requiresStepUp` via `check()` and drive the [step-up flow](/guides/step-up) — don't hard-fail the user
when you could let them elevate.
:::

::: callout success "Tune the http timeout deliberately" icon:timer
`http.timeout` (default 5s) is the cap on how long a decision can block a request. Set it from your latency
budget: long enough to avoid spurious denies under normal jitter, short enough that a hung PDP doesn't hang
your pages.
:::

## Don't

::: callout danger "Don't reach for a fail-open escape hatch"
There is no `fail_open` key, and you should not synthesize one (e.g. catching a deny and allowing anyway). An
unavailable PDP that *allows* is an unbounded grant — the exact failure mode the design exists to prevent.
:::

::: callout danger "Don't gate on allowed"
`allowed === true` can still mean "only after step-up". Branching on it skips assurance. Gate on `granted()`.
:::

::: callout warning "Don't cache your way around revocation"
A long TTL hides revocations for its duration on each node. For actions that demand immediate revocation,
lower the TTL or check with `explain => true` (which bypasses the cache).
:::

## Degrading gracefully — the right way

If a feature genuinely needs to keep working during a PDP outage, make that an **explicit, scoped application
decision** — never a transport default:

```php
$decision = Iam::check($user, 'reports:view');

if (! $decision->granted()) {
    // The PDP denied (possibly because it's unreachable). Decide, per feature,
    // what "denied right now" should mean for the user experience:
    return response()->view('reports.unavailable', status: 503);
}
```

You can layer your *own* cautious fallback for a specific low-risk, read-only action (e.g. show stale,
clearly-labelled data) — but you do it consciously, at one call site, with the risk visible. You never flip a
global switch that turns every deny into an allow.

## A pre-flight checklist

::: steps
1. **Mode chosen on purpose** — `local` where you can, `http` where you must, documented either way.
2. **Timeout set from a latency budget** — not left implicit.
3. **Cache TTL set from a revocation budget** — the max staleness you accept.
4. **Shared cache store for multi-node** — so the fleet caches consistently.
5. **Step-up handled where it matters** — sensitive actions offer elevation, not a dead end.
6. **No home-grown fail-open** — verified in review.
:::

## See also

- [Fail-closed authorization](/concepts/fail-closed) — the formal argument.
- [Cache decisions](/guides/cache-decisions)
- [Deployment topologies](/operations/deployment-topologies)
