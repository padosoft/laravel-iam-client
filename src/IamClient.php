<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client;

use Illuminate\Contracts\Auth\Authenticatable;
use Padosoft\Iam\Client\Contracts\Decider;

/**
 * Facciata applicativa del client (doc 06). Traduce `can($user, 'app:permesso', $context)` in una
 * `DecisionRequest` verso il PDP. Le chiavi riservate del context (`organization`, `application`,
 * `resource`, `aal`, `explain`) diventano parametri di query; il resto è context ABAC. Fail-closed:
 * senza un subject identificabile → deny.
 */
final class IamClient
{
    /** @param array<string, mixed> $config sezione `iam-client` */
    public function __construct(
        private readonly Decider $decider,
        private readonly array $config = [],
    ) {}

    /**
     * Consentito davvero (permit del PDP + nessuno step-up pendente).
     *
     * @param  array<string, mixed>  $context
     */
    public function can(Authenticatable|string|null $user, string $ability, array $context = []): bool
    {
        return $this->check($user, $ability, $context)->granted();
    }

    /** @param array<string, mixed> $context */
    public function denies(Authenticatable|string|null $user, string $ability, array $context = []): bool
    {
        return !$this->can($user, $ability, $context);
    }

    /**
     * Decisione completa (per chi gestisce step-up/explain).
     *
     * @param  array<string, mixed>  $context
     */
    public function check(Authenticatable|string|null $user, string $ability, array $context = []): IamDecision
    {
        $subjectId = $this->resolveSubjectId($user);
        if ($subjectId === '') {
            return IamDecision::deny('no-subject');
        }

        return $this->decider->decide($this->request($subjectId, $ability, $context));
    }

    /** @param array<string, mixed> $context */
    public function request(string $subjectId, string $ability, array $context = []): DecisionRequest
    {
        $organization = $this->pull($context, 'organization') ?? $this->stringConfig('default_organization');
        $application = $this->pull($context, 'application') ?? $this->stringConfig('default_application');
        $resource = $this->pull($context, 'resource');
        $aal = $this->pull($context, 'aal') ?? 'aal1';
        $explain = $context['explain'] ?? false;
        unset($context['explain']);

        return new DecisionRequest(
            permission: $ability,
            subjectId: $subjectId,
            subjectType: $this->stringConfig('subject_type') ?? 'user',
            organization: $organization,
            application: $application,
            resource: $resource,
            context: $context,
            currentAal: $aal !== '' ? $aal : 'aal1',
            explain: $explain === true,
        );
    }

    public function resolveSubjectId(Authenticatable|string|null $user): string
    {
        if ($user === null) {
            return '';
        }
        if (is_string($user)) {
            return $user;
        }

        $id = $user->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * Estrae e RIMUOVE una chiave riservata dal context, così che ciò che resta sia solo ABAC.
     *
     * @param  array<string, mixed>  $context
     */
    private function pull(array &$context, string $key): ?string
    {
        if (!array_key_exists($key, $context)) {
            return null;
        }
        $value = $context[$key];
        unset($context[$key]);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
