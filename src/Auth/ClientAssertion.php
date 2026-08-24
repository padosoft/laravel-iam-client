<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;

/**
 * Costruttore dell'assertion `private_key_jwt` (RFC 7523): un ES256 a vita breve con
 * iss=sub=client_id, aud=token endpoint, jti single-use. È la STESSA assertion per
 * client_credentials (PrivateKeyJwtTokenProvider) e per il Token Exchange RFC 8693
 * (HttpTokenExchanger): l'agente si autentica sempre con la propria chiave, mai con
 * un secret condiviso.
 */
final class ClientAssertion
{
    public const string TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private function __construct() {}

    public static function build(
        string $clientId,
        string $privateKeyPem,
        string $tokenEndpoint,
        ?string $kid,
        int $ttlSeconds = 60,
    ): string {
        if ($clientId === '' || $privateKeyPem === '' || $tokenEndpoint === '') {
            throw new \RuntimeException('private_key_jwt requires a non-empty client_id, private key and token endpoint');
        }
        $now = new \DateTimeImmutable;
        $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->issuedBy($clientId)                              // iss
            ->relatedTo($clientId)                             // sub
            ->permittedFor($tokenEndpoint)                     // aud = token endpoint
            ->identifiedBy(bin2hex(random_bytes(16)))          // jti (single-use)
            ->issuedAt($now)
            ->expiresAt($now->modify("+{$ttlSeconds} seconds"));
        if ($kid !== null && $kid !== '') {
            $builder = $builder->withHeader('kid', $kid);
        }

        return $builder->getToken(new Sha256, InMemory::plainText($privateKeyPem))->toString();
    }
}
