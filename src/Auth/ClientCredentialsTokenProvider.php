<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Ottiene un access token via **client_credentials** (client_id + client_secret) e lo cacha fino a poco
 * prima della scadenza. Se il secret è stato **auto-ruotato** dal server (token endpoint → 401
 * invalid_client), lo ri-scarica dall'endpoint di **self-fetch** autenticandosi col secret ancora valido
 * nella finestra di grace, lo persiste in cache e riprova → l'app non si rompe mai su una rotazione
 * automatica. Fail-closed: se non riesce a ottenere un token, resolve() torna null (il PDP negherà).
 */
final class ClientCredentialsTokenProvider implements TokenProvider
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $oauthUrl,   // es. https://iam.example.com/oauth
        private readonly string $clientId,
        private readonly string $configSecret,
        private readonly Cache $cache,
        private readonly int $skew = 30,     // rinnova il token N secondi prima della scadenza
    ) {}

    public function resolve(): ?string
    {
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
            ]);
            if ($res->getStatusCode() !== 200) {
                return false;
            }
            $body = json_decode((string) $res->getBody(), true);
            if (is_array($body) && ($body['rotated'] ?? false) === true && is_string($body['client_secret'] ?? null) && $body['client_secret'] !== '') {
                $this->cache->forever($this->secretKey(), $body['client_secret']);

                return true;
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function currentSecret(): string
    {
        $stored = $this->cache->get($this->secretKey());

        return is_string($stored) && $stored !== '' ? $stored : $this->configSecret;
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
