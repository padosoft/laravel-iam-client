<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Crypt;
use Padosoft\Iam\Client\Support\TransportGuard;

/**
 * Ottiene un access token via **client_credentials** (client_id + client_secret) e lo cacha fino a poco
 * prima della scadenza. Se il secret è stato **auto-ruotato** dal server (token endpoint → 401
 * invalid_client), lo ri-scarica dall'endpoint di **self-fetch** autenticandosi col secret ancora valido
 * nella finestra di grace, lo persiste in cache e riprova → l'app non si rompe mai su una rotazione
 * automatica. Fail-closed: se non riesce a ottenere un token, resolve() torna null (il PDP negherà).
 */
final class ClientCredentialsTokenProvider implements TokenProvider
{
    /** IAM-25: TTL del secret auto-ruotato in cache (mai `forever`) — 30 giorni, ben oltre una rotazione. */
    private const ROTATED_SECRET_TTL = 2592000;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $oauthUrl,   // es. https://iam.example.com/oauth
        private readonly string $clientId,
        private readonly string $configSecret,
        private readonly Cache $cache,
        private readonly int $skew = 30,     // rinnova il token N secondi prima della scadenza
        private readonly bool $allowInsecureTransport = false, // IAM-39: http:// ammesso solo se true (dev)
    ) {}

    public function resolve(): ?string
    {
        // IAM-39: non spedire MAI client_secret/bearer su un endpoint non-https. Un URL http:// (salvo
        // localhost o allow-insecure esplicito) è fail-closed → null (il PDP negherà) invece di leak in chiaro.
        if (!TransportGuard::allows($this->oauthUrl, $this->allowInsecureTransport)) {
            return null;
        }

        $cached = $this->cache->get($this->tokenKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->mint();
    }

    private function mint(): ?string
    {
        $token = $this->requestToken($this->currentSecret());
        // 401 → il secret potrebbe essere stato auto-ruotato: prova a ritirarne uno nuovo e riprova UNA volta.
        if ($token === null && $this->fetchRotatedSecret()) {
            $token = $this->requestToken($this->currentSecret());
        }

        return $token;
    }

    private function requestToken(string $secret): ?string
    {
        try {
            $res = $this->http->request('POST', $this->url('token'), [
                'headers' => ['Accept' => 'application/json'],
                'auth' => [$this->clientId, $secret], // client_secret_basic
                'form_params' => ['grant_type' => 'client_credentials'],
                'http_errors' => false,
                'allow_redirects' => false, // IAM-39b: mai seguire un 30x https→http (leak del secret)
            ]);
            if ($res->getStatusCode() !== 200) {
                return null;
            }
            $body = json_decode((string) $res->getBody(), true);
            if (!is_array($body) || !is_string($body['access_token'] ?? null) || $body['access_token'] === '') {
                return null;
            }
            $exp = is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 900;
            $this->cache->put($this->tokenKey(), $body['access_token'], max(1, $exp - $this->skew));

            return $body['access_token'];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Ritira il secret auto-ruotato dal server (POST /oauth/client-secret) autenticandosi col corrente. */
    private function fetchRotatedSecret(): bool
    {
        try {
            $res = $this->http->request('POST', $this->url('client-secret'), [
                'headers' => ['Accept' => 'application/json'],
                'auth' => [$this->clientId, $this->currentSecret()],
                'http_errors' => false,
                'allow_redirects' => false, // IAM-39b: nessun redirect su una richiesta autenticata col secret
            ]);
            if ($res->getStatusCode() !== 200) {
                return false;
            }
            $body = json_decode((string) $res->getBody(), true);
            if (is_array($body) && ($body['rotated'] ?? false) === true && is_string($body['client_secret'] ?? null) && $body['client_secret'] !== '') {
                // IAM-25: cifra il secret at-rest (Crypt/APP_KEY) e con un TTL limitato — mai in chiaro,
                // mai `forever`. La cache non deve diventare una copia recuperabile in chiaro di una credenziale.
                $this->cache->put($this->secretKey(), Crypt::encryptString($body['client_secret']), self::ROTATED_SECRET_TTL);

                return true;
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function currentSecret(): string
    {
        // IAM-25: il secret ruotato è cifrato in cache; decifra.
        $stored = $this->cache->get($this->secretKey());
        if (is_string($stored) && $stored !== '') {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable) {
                // Backward-compat: una release precedente cacheva il secret ruotato in CHIARO (forever).
                // NON scartarlo (regredirebbe a fail-closed durante il rollover, usando un config secret
                // ormai vecchio): trattalo come legacy-plaintext, ri-cifralo con TTL (migrazione one-time)
                // e usalo. Se anche la ri-cifratura fallisce, usa comunque il valore legacy.
                try {
                    $this->cache->put($this->secretKey(), Crypt::encryptString($stored), self::ROTATED_SECRET_TTL);
                } catch (\Throwable) {
                    // best-effort re-encrypt; il valore legacy resta usabile
                }

                return $stored;
            }
        }

        return $this->configSecret;
    }

    private function url(string $path): string
    {
        return rtrim($this->oauthUrl, '/').'/'.$path;
    }

    private function tokenKey(): string
    {
        return 'iam-client:cc-token:'.sha1($this->clientId.'|'.$this->oauthUrl);
    }

    private function secretKey(): string
    {
        return 'iam-client:cc-secret:'.sha1($this->clientId.'|'.$this->oauthUrl);
    }
}
