<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;

/**
 * private_key_jwt (RFC 7523): authenticate to the token endpoint with a SIGNED ASSERTION instead of a shared
 * secret. Each mint signs a short-lived ES256 JWT (iss=sub=client_id, aud=token endpoint, unique jti, brief
 * exp) with the app's private key and exchanges it for an access token via client_credentials; the token is
 * cached until just before it expires. No secret ever leaves the app. Fail-closed: resolve() returns null on
 * any failure (→ no Authorization header → the PDP denies).
 */
final class PrivateKeyJwtTokenProvider implements TokenProvider
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $oauthUrl,   // es. https://iam.example.com/oauth
        private readonly string $clientId,
        private readonly string $privateKeyPem,
        private readonly ?string $kid,
        private readonly Cache $cache,
        private readonly int $skew = 30,           // rinnova il token N secondi prima della scadenza
        private readonly int $assertionTtl = 60,   // vita dell'assertion (breve; il server la limita)
    ) {}

    public function resolve(): ?string
    {
        $cached = $this->cache->get($this->tokenKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            return $this->mint();
        } catch (\Throwable) {
            return null;
        }
    }

    private function mint(): ?string
    {
        $res = $this->http->request('POST', rtrim($this->oauthUrl, '/').'/token', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $this->buildAssertion(),
            ],
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
    }

    private function buildAssertion(): string
    {
        if ($this->clientId === '' || $this->privateKeyPem === '') {
            throw new \RuntimeException('private_key_jwt requires a non-empty client_id and private key');
        }
        $now = new \DateTimeImmutable;
        $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->issuedBy($this->clientId)                              // iss
            ->relatedTo($this->clientId)                            // sub
            ->permittedFor(rtrim($this->oauthUrl, '/').'/token')     // aud = token endpoint
            ->identifiedBy(bin2hex(random_bytes(16)))                // jti (single-use)
            ->issuedAt($now)
            ->expiresAt($now->modify("+{$this->assertionTtl} seconds"));
        if ($this->kid !== null && $this->kid !== '') {
            $builder = $builder->withHeader('kid', $this->kid);
        }

        return $builder->getToken(new Sha256, InMemory::plainText($this->privateKeyPem))->toString();
    }

    private function tokenKey(): string
    {
        return 'iam-client:pkjwt-token:'.sha1($this->clientId.'|'.$this->oauthUrl);
    }
}
