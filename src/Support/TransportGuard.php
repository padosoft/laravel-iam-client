<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Support;

/**
 * IAM-39 / IAM-39b: guardia di trasporto fail-closed. Nessuna credenziale (client_secret, assertion
 * private_key_jwt, Bearer) né la stessa decision call devono viaggiare su `http://` in chiaro. `https` è
 * sempre ammesso; `http` solo su loopback (dev locale) o con `allow_insecure` esplicito; uno scheme
 * assente/sconosciuto è negato. Centralizzata qui così ogni percorso (token endpoint E decision call)
 * applica la STESSA regola — l'invariante "il transport è sempre fail-closed" non ha buchi per-percorso.
 */
final class TransportGuard
{
    /** @var list<string> */
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1'];

    /**
     * True se è lecito spedire credenziali/decisioni a `$url`. `https` sempre; `http` solo loopback o
     * `$allowInsecure`; scheme assente/altro → false (fail-closed).
     */
    public static function allows(string $url, bool $allowInsecure): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme === 'http') {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            return $allowInsecure || in_array($host, self::LOOPBACK, true);
        }

        return false;
    }
}
