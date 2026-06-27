<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Gate;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Padosoft\Iam\Client\IamClient;

/**
 * Gate adapter (doc 07 §12, enforce): registra `Gate::before` per delegare a IAM le ability che gli
 * appartengono. Coesistenza: di default intercetta SOLO le ability "namespaced" (con `:`, forma
 * `app:permesso`), restituendo `null` sulle altre per non scavalcare le Gate/policy locali.
 */
final class IamGateAdapter
{
    public function __construct(
        private readonly IamClient $client,
        private readonly string $intercept = 'namespaced',
    ) {}

    public function register(Gate $gate): void
    {
        $gate->before(function (Authenticatable $user, string $ability, array $arguments = []) {
            return $this->decide($user, $ability, $arguments);
        });
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return bool|null true=consenti, false=nega (corto-circuita), null=lascia decidere le Gate locali
     */
    public function decide(Authenticatable $user, string $ability, array $arguments = []): ?bool
    {
        if (!$this->owns($ability)) {
            return null;
        }

        // enforce: l'esito di IAM è vincolante. `granted()` è fail-safe sullo step-up (un permit che
        // richiede AAL più alto NON concede finché lo step-up non è soddisfatto).
        return $this->client->check($user, $ability, $this->context($arguments))->granted();
    }

    private function owns(string $ability): bool
    {
        if ($this->intercept === 'all') {
            return true;
        }

        // 'namespaced': solo le ability con ':' (app:permesso) sono di IAM.
        return str_contains($ability, ':');
    }

    /**
     * Primo argomento stringa → resource ref (es. `Gate::allows('app:perm', 'wh_milan')`).
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function context(array $arguments): array
    {
        $first = $arguments[0] ?? null;

        return is_string($first) && $first !== '' ? ['resource' => $first] : [];
    }
}
