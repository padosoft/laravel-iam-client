<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Gate;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Padosoft\Iam\Client\IamClient;

/**
 * Gate adapter (doc 07 §12, enforce): registra `Gate::before` per delegare a IAM le ability che gli
 * appartengono. Coesistenza: di default intercetta SOLO le ability "namespaced" (con `:`, forma
 * `app:permesso`), restituendo `null` sulle altre per non scavalcare le Gate/policy locali.
 *
 * IAM-40: se sono configurati degli `appKeys`, intercetta SOLO le ability il cui prefisso (`app:`) è tra
 * quelli noti a IAM — così un'ability namespaced di terze parti (es. `log:viewer`) non viene rivendicata
 * e negata da IAM. Senza appKeys configurati resta il comportamento storico (tutte le namespaced).
 */
final class IamGateAdapter
{
    /**
     * @param  list<string>  $appKeys  prefissi app noti a IAM (vuoto = intercetta tutte le namespaced)
     */
    public function __construct(
        private readonly IamClient $client,
        private readonly string $intercept = 'namespaced',
        private readonly array $appKeys = [],
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
        if (!str_contains($ability, ':')) {
            return false;
        }

        // IAM-40: se sono noti gli app_key, intercetta SOLO `<appKey>:...` — non rivendicare ability
        // namespaced di terze parti che IAM non conosce (le negherebbe scavalcando la policy locale).
        if ($this->appKeys !== []) {
            $prefix = strstr($ability, ':', true);

            return $prefix !== false && in_array($prefix, $this->appKeys, true);
        }

        return true;
    }

    /**
     * Primo argomento → resource ref. IAM-24: un Model (route/model binding, `Gate::allows('app:perm',
     * $warehouse)`) va risolto alla sua chiave come fa IamCan — scartarlo trasformerebbe una check
     * per-risorsa in una globale (over-auth).
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function context(array $arguments): array
    {
        $ref = $this->resourceRef($arguments[0] ?? null);

        return $ref !== null ? ['resource' => $ref] : [];
    }

    private function resourceRef(mixed $resource): ?string
    {
        if ($resource instanceof Model) {
            $key = $resource->getKey();

            return is_scalar($key) ? (string) $key : null;
        }

        return is_scalar($resource) && (string) $resource !== '' ? (string) $resource : null;
    }
}
