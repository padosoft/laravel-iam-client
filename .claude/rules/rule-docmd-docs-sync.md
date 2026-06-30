---
name: docmd-docs-sync
description: Binding rule — keep the docs-site/ documentation in sync with user-facing changes to laravel-iam-client.
severity: blocking
---

# Rule: documentation must stay in sync (BLOCKING)

This is an imperative, blocking rule. It is not advisory.

## When it applies

**Every time** you add or change a user-facing feature, behavior, or public API of this package — or update
the README in a substantive way — you MUST, in the **same unit of work**, update the corresponding docmd
page(s) under `docs-site/docs/**`, following the `docmd-docs` skill.

User-facing surface of this package includes (non-exhaustive):

- the `Iam` facade / `IamClient` methods (`can`, `denies`, `check`, `request`, `resolveSubjectId`);
- the `Decider` contract and the `LocalDecider` / `HttpDecider` / `CachingDecider` transports (including the
  HTTP endpoint shape, headers, the `{data}` unwrap, and fail-closed reasons);
- the `iam.can` / `iam.auth` middleware (aliases, arguments, status codes, resource resolution);
- the `IamGateAdapter` (interception mode, resource-from-argument behavior);
- the `DecisionRequest` / `IamDecision` shapes (`toArray` / `fromArray` / `cacheKey` / `granted`);
- any `config/iam-client.php` key or environment variable;
- service-provider wiring (transport selection, cache wrapping, alias non-clobbering, gate registration).

## What you MUST do

1. Edit (or add) the matching page(s) in `docs-site/docs/**`. If you add a page, **register it in
   `navigation[]`** in `docs-site/docmd.config.json` — an unlisted page does not appear.
2. Keep examples and signatures byte-accurate to `src/`. No invented features (e.g. do NOT document OIDC
   login, JWT/JWKS, introspection, or a webhook receiver — they are not in `src/`).
3. Preserve the page-structure standard from the `docmd-docs` skill (motivation -> theory -> mermaid ->
   contract -> ADR -> worked example -> gotchas) for deep pages.
4. Before considering the work done, run inside `docs-site/`:
   ```bash
   npm run check && npm run build
   ```
   Both MUST be green, `_site/index.html` MUST exist, and there must be 0 `:::` rendered as visible text.

## When it does NOT apply

Purely internal changes with no user-facing effect: internal refactors, private-method changes, tooling/CI
fixes, dependency bumps with no behavior change, test-only changes, or cosmetic edits. In these cases, state
explicitly in the commit/PR description that no docs change is required and why.

## Anti-patterns (treat as failures)

- Shipping a feature or behavior change without the corresponding docs update.
- Adding a page that is not registered in `navigation[]`.
- Using MDX/JSX or raw HTML tags (`<Card>`, `<Note>`, …) — the `check` guard rejects them.
- Documenting behavior the code does not have, or leaving a stale endpoint/signature/config key in the docs.
- Marking work complete without a green `npm run check && npm run build`.
