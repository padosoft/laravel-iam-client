<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Support;

/**
 * Ispezione LOCALE (senza verifica di firma — il client non custodisce chiavi) di un
 * bearer JWT per capire se è un token DELEGATO: header `typ: delegated+jwt` o claim
 * `act`. Serve SOLO a instradare: un token delegato va autorizzato via introspection
 * + decisione delegata, MAI dal contenuto locale.
 *
 * Fail-closed: un token che SEMBRA delegato ma è malformato (act illeggibile, sub
 * assente) LANCIA — non degrada mai a "token utente normale". Il degrado silenzioso
 * è esattamente il confused-deputy che la delega esiste per prevenire.
 */
final class DelegatedBearerInspector
{
    public const TYP_DELEGATED = 'delegated+jwt';

    /**
     * @return DelegatedBearer|null null = NON delegato (procedi col flusso normale)
     *
     * @throws \InvalidArgumentException token delegato malformato (MAI trattarlo come utente pieno)
     */
    public function inspect(string $jwt): ?DelegatedBearer
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null; // non è nemmeno un JWT: non delegato
        }

        $header = $this->decode($parts[0]);
        $claims = $this->decode($parts[1]);
        if ($header === null || $claims === null) {
            return null;
        }

        $typDelegated = ($header['typ'] ?? null) === self::TYP_DELEGATED;
        $hasAct = array_key_exists('act', $claims);
        if (!$typDelegated && !$hasAct) {
            return null;
        }

        // Da qui in poi il token È delegato: ogni difetto lancia (fail-closed).
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new \InvalidArgumentException('Token delegato senza sub.');
        }

        $actors = $this->actorChain($claims['act'] ?? null);

        $grantId = $claims['pds_dgr'] ?? null;
        $scope = $claims['scope'] ?? '';

        return new DelegatedBearer(
            sub: $sub,
            actors: $actors,
            grantId: is_string($grantId) && $grantId !== '' ? $grantId : null,
            scopes: is_string($scope) && $scope !== '' ? array_values(array_filter(explode(' ', $scope))) : [],
            verified: false,
        );
    }

    /**
     * Catena `act` annidata (RFC 8693 §4.1) → lista `agent:<id>`, attore corrente per primo.
     *
     * @return non-empty-list<string>
     */
    private function actorChain(mixed $act): array
    {
        if (!is_array($act)) {
            throw new \InvalidArgumentException('Token delegato con claim act malformato.');
        }

        $actors = [];
        $level = $act;
        while (true) {
            $sub = $level['sub'] ?? null;
            if (!is_string($sub) || !str_starts_with($sub, 'agent:') || strlen($sub) <= 6) {
                throw new \InvalidArgumentException('Claim act: livello senza sub agent valido.');
            }
            $actors[] = $sub;

            $next = $level['act'] ?? null;
            if ($next === null) {
                break;
            }
            if (!is_array($next)) {
                throw new \InvalidArgumentException('Claim act: annidamento malformato.');
            }
            $level = $next;
        }

        return $actors;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $b64url): ?array
    {
        $decoded = base64_decode(strtr($b64url, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            return null;
        }

        $out = [];
        foreach ($json as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
