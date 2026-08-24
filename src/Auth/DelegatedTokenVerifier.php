<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

use GuzzleHttp\ClientInterface;
use Padosoft\Iam\Client\Support\DelegatedBearer;
use Padosoft\Iam\Client\Support\DelegatedBearerInspector;
use Padosoft\Iam\Client\Support\TransportGuard;

/**
 * Verifica di un token DELEGATO: i token con claim `act` sono INTROSPECTION-MANDATORY
 * (RFC 7662) — la vista autorizzativa nasce dai claims restituiti dal server (che
 * verifica firma, scadenza E vitalità della sessione utente), mai dal parse locale.
 * Il `typ: delegated+jwt` è instradamento, non una difesa.
 *
 * Fail-closed SENZA eccezioni verso il chiamante: qualunque errore (config assente,
 * trasporto, token inattivo, claims incoerenti) ⇒ null ⇒ 401 a valle.
 */
final class DelegatedTokenVerifier
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly DelegatedBearerInspector $inspector,
        private readonly string $introspectionUrl,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly bool $allowInsecure = false,
    ) {}

    /**
     * @return DelegatedBearer|null la vista VERIFICATA (dai claims di introspection), o null (deny)
     */
    public function verify(string $jwt): ?DelegatedBearer
    {
        try {
            $local = $this->inspector->inspect($jwt);
        } catch (\InvalidArgumentException) {
            return null; // delegato malformato: mai degradare
        }
        if ($local === null) {
            return null; // non delegato: questo verifier non è il suo percorso
        }

        if ($this->introspectionUrl === '' || !TransportGuard::allows($this->introspectionUrl, $this->allowInsecure)) {
            return null; // niente introspection possibile ⇒ niente autorizzazione delegata
        }

        try {
            $response = $this->http->request('POST', $this->introspectionUrl, [
                'headers' => ['Accept' => 'application/json'],
                'form_params' => array_filter([
                    'token' => $jwt,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ], static fn ($v): bool => is_string($v) && $v !== ''),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = json_decode((string) $response->getBody(), true);
            if (!is_array($body) || ($body['active'] ?? false) !== true) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        // La vista autorizzativa nasce dai claims INTROSPETTATI (la verità server-side).
        $sub = $body['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return null;
        }
        try {
            $chain = $this->chainFrom($body) ?? $local->actors;
            $grantId = $body['pds_dgr'] ?? null;
            $scope = $body['scope'] ?? null;

            return new DelegatedBearer(
                sub: $sub,
                actors: $chain,
                grantId: is_string($grantId) && $grantId !== '' ? $grantId : $local->grantId,
                scopes: is_string($scope) && $scope !== ''
                    ? array_values(array_filter(explode(' ', $scope)))
                    : $local->scopes,
                verified: true,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return non-empty-list<string>|null
     */
    private function chainFrom(array $body): ?array
    {
        $act = $body['act'] ?? null;
        if (!is_array($act)) {
            return null;
        }

        $actors = [];
        $level = $act;
        while (true) {
            $sub = $level['sub'] ?? null;
            if (!is_string($sub) || !str_starts_with($sub, 'agent:') || strlen($sub) <= 6) {
                throw new \InvalidArgumentException('Introspection: act malformato.');
            }
            $actors[] = $sub;
            $next = $level['act'] ?? null;
            if ($next === null) {
                break;
            }
            if (!is_array($next)) {
                throw new \InvalidArgumentException('Introspection: act annidato malformato.');
            }
            $level = $next;
        }

        return $actors;
    }
}
