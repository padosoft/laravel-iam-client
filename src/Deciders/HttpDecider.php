<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Deciders;

use GuzzleHttp\ClientInterface;
use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\DecisionRequest;
use Padosoft\Iam\Client\IamDecision;

/**
 * Trasporto remoto (doc 06): l'app consumer interroga l'Admin API del server IAM
 * (`POST /decisions:check`) con un Bearer. Fail-closed SENZA eccezioni: qualunque errore di
 * trasporto/HTTP non-2xx o body inatteso → DENY. Non esiste un opt-out fail-open: un PDP
 * irraggiungibile non deve mai aprire le porte (chi vuole tollerare un outage lo gestisce a livello
 * applicativo, consapevolmente, non nel transport).
 */
final class HttpDecider implements Decider
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $baseUrl,
        private readonly ?string $token,
    ) {}

    public function decide(DecisionRequest $request): IamDecision
    {
        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/').'/decisions:check', [
                'headers' => array_filter([
                    'Accept' => 'application/json',
                    'Authorization' => $this->token !== null ? 'Bearer '.$this->token : null,
                ]),
                'json' => $request->toArray(),
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return IamDecision::deny("http {$status}");
            }

            $decoded = json_decode((string) $response->getBody(), true);
            if (!is_array($decoded)) {
                return IamDecision::deny('invalid body');
            }

            return IamDecision::fromArray($decoded);
        } catch (\Throwable $e) {
            return IamDecision::deny('transport: '.$e::class);
        }
    }
}
