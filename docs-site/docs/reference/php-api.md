---
title: PHP API
description: The public classes of laravel-iam-client and their exact signatures — IamClient, the Iam facade, DecisionRequest, IamDecision, the Decider transports, the Gate adapter, and the service provider.
---

# PHP API

All classes live under the `Padosoft\Iam\Client\` namespace.

## `Facades\Iam`

```php
Iam::can(Authenticatable|string|null $user, string $ability, array $context = []): bool
Iam::denies(Authenticatable|string|null $user, string $ability, array $context = []): bool
Iam::check(Authenticatable|string|null $user, string $ability, array $context = []): IamDecision
```

Backed by `IamClient`. Reserved `$context` keys (`organization`, `application`, `resource`, `aal`, `explain`)
are mapped onto the query; everything else is sent as ABAC facts.

## `IamClient`

The class behind the facade.

```php
public function __construct(Decider $decider, array $config = [])

public function can(Authenticatable|string|null $user, string $ability, array $context = []): bool
public function denies(Authenticatable|string|null $user, string $ability, array $context = []): bool
public function check(Authenticatable|string|null $user, string $ability, array $context = []): IamDecision
public function request(string $subjectId, string $ability, array $context = []): DecisionRequest
public function resolveSubjectId(Authenticatable|string|null $user): string
```

| Method | Returns | Notes |
|---|---|---|
| `can` | `bool` | `check(...)->granted()` |
| `denies` | `bool` | `!can(...)` |
| `check` | `IamDecision` | `deny('no-subject')` when no subject resolves (fail-closed) |
| `request` | `DecisionRequest` | builds the DTO; pulls reserved keys, applies config defaults |
| `resolveSubjectId` | `string` | `getAuthIdentifier()` (scalar) / the string / `''` for null |

## `Contracts\Decider`

```php
interface Decider {
    public function decide(DecisionRequest $request): IamDecision;
}
```

### `Deciders\LocalDecider`

```php
public function __construct(AuthorizationEngine $engine)
public function decide(DecisionRequest $request): IamDecision
```

Calls the in-process PDP. Any throwable → `IamDecision::deny('engine: ' . $e::class)`.

### `Deciders\HttpDecider`

```php
public function __construct(ClientInterface $http, string $baseUrl, ?string $token)
public function decide(DecisionRequest $request): IamDecision
```

`POST {baseUrl}/decisions/check` with `Accept: application/json` and (if `$token`) `Authorization: Bearer`.
`http_errors => false`. Non-2xx → `deny("http {status}")`; non-array body → `deny('invalid body')`; throwable
→ `deny('transport: ' . $e::class)`. Unwraps the `{ "data": ... }` envelope before parsing.

### `Deciders\CachingDecider`

```php
public function __construct(Decider $inner, CacheRepository $cache, int $ttl, bool $enabled = true)
public function decide(DecisionRequest $request): IamDecision
```

Bypasses (delegates to `$inner`) when `!$enabled`, `$ttl <= 0`, or `$request->explain`. Otherwise reads/writes
`'iam:dec:' . $request->cacheKey()`, storing `IamDecision::toArray()` for `$ttl` seconds.

## `DecisionRequest` (`final readonly`)

```php
public function __construct(
    string  $permission,
    string  $subjectId,
    string  $subjectType = 'user',
    ?string $organization = null,
    ?string $application = null,
    ?string $resource = null,
    array   $context = [],          // ABAC facts
    string  $currentAal = 'aal1',
    bool    $explain = false,
)

public function cacheKey(): string   // sha256 over subjectType, subjectId, permission,
                                     //   organization, application, resource, context, currentAal
public function toArray(): array     // { subject:{type,id}, permission, organization, application,
                                     //   resource, context, current_aal, explain }
```

## `IamDecision` (`final readonly`)

```php
public function __construct(
    bool    $allowed,
    string  $decisionId = '',
    int     $policyVersion = 0,
    bool    $requiresStepUp = false,
    ?string $requiredAal = null,
    array   $explanation = [],       // list<string>
)

public static function deny(string $reason): self          // fail-closed denial
public static function fromArray(array $data): self         // parse the PDP response (defensive)
public function granted(): bool                             // allowed && !requiresStepUp  ← gate on this
public function toArray(): array
```

`fromArray()` reads `allowed`, `decision_id`, `policy_version`, `requires_step_up`, `required_aal`,
`explanation`, each with a safe fallback (`allowed`/`requires_step_up` default to `false`).

## `Gate\IamGateAdapter`

```php
public function __construct(IamClient $client, string $intercept = 'namespaced')
public function register(Gate $gate): void
public function decide(Authenticatable $user, string $ability, array $arguments = []): ?bool
```

`register()` hooks `Gate::before`. `decide()` returns `null` for abilities it doesn't own (so local
Gates/policies decide), otherwise `IamClient::check(...)->granted()`. Owns: with `intercept = 'all'`, every
ability; with `'namespaced'`, abilities containing `:`. A non-empty **string** first argument becomes the
`resource`.

## Middleware

```php
// Http\Middleware\IamAuthenticate — alias iam.auth
public function handle(Request $request, Closure $next): Response   // 401 when $request->user() is null

// Http\Middleware\IamCan — alias iam.can
public function handle(
    Request $request, Closure $next, string $permission, ?string $resourceParam = null
): Response   // 401 without a user; 403 when !granted; binds $resourceParam (route value or model key) as resource
```

## `IamClientServiceProvider`

Extends `spatie/laravel-package-tools`. Binds `Decider`, `IamClient`, `IamGateAdapter` as singletons;
registers the `iam.can` / `iam.auth` aliases **only if not already registered**; registers the Gate adapter
when `gate.enabled` is `true`. Selects `HttpDecider` vs `LocalDecider` from `mode`, and wraps in
`CachingDecider` when `cache.enabled`.

## See also

- [The decision contract](/concepts/decision-contract) — request/response JSON shapes.
- [Middleware & Gate reference](/reference/middleware-and-gate)
- [Config & env reference](/reference/config-and-env)
