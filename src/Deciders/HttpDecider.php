<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Deciders;

use GuzzleHttp\ClientInterface;
use Padosoft\Iam\Client\Auth\StaticTokenProvider;
use Padosoft\Iam\Client\Auth\TokenProvider;
use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\DecisionRequest;
use Padosoft\Iam\Client\IamDecision;
use Padosoft\Iam\Client\Support\TransportGuard;

/**
 * Trasporto remoto (doc 06): l'app consumer interroga l'Admin API del server IAM
 * (`POST /decisions/check`) con un Bearer. Fail-closed SENZA eccezioni: qualunque errore di
 * trasporto/HTTP non-2xx o body inatteso → DENY. Non esiste un opt-out fail-open: un PDP
 * irraggiungibile non deve mai aprire le porte (chi vuole tollerare un outage lo gestisce a livello
 * applicativo, consapevolmente, non nel transport).
 */
final class HttpDecider implements Decider
{
    private readonly TokenProvider $tokens;

    /**
     * @param  TokenProvider|string|null  $tokens  A TokenProvider, or — for backward compatibility — a raw
     *                                             static bearer token (or null for none), wrapped transparently.
     * @param  bool  $allowInsecure  IAM-39b: consenti la decision call (che porta il Bearer) su `http://`.
     *                               Default false = fail-closed: un `base_url` non-https (salvo loopback)
     *                               nega invece di spedire il token in chiaro.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $baseUrl,
        TokenProvider|string|null $tokens,
        private readonly bool $allowInsecure = false,
    ) {
        $this->tokens = $tokens instanceof TokenProvider ? $tokens : new StaticTokenProvider($tokens);
    }

    public function decide(DecisionRequest $request): IamDecision
    {
        // IAM-39b: la decision call trasporta il Bearer verso `base_url`. Se non è https (salvo loopback o
        // allow_insecure), NON spedire in chiaro: nega (fail-closed) invece di leakare la credenziale.
        if (!TransportGuard::allows($this->baseUrl, $this->allowInsecure)) {
            return IamDecision::deny('insecure transport');
        }

        try {
            $token = $this->tokens->resolve();
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/').'/decisions/check', [
                'headers' => array_filter([
                    'Accept' => 'application/json',
                    'Authorization' => $token !== null ? 'Bearer '.$token : null,
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

            // L'Admin API avvolge ogni risposta in `{ "data": {...} }` (AdminController::ok()).
            // Scartiamo l'envelope in modo trasparente; un body già flat (es. PDP locale dietro
            // un proxy) resta valido. Senza questo unwrap, fromArray leggerebbe il livello
            // sbagliato e ogni decisione diventerebbe un deny silenzioso.
            $payload = (isset($decoded['data']) && is_array($decoded['data'])) ? $decoded['data'] : $decoded;

            return IamDecision::fromArray($payload);
        } catch (\Throwable $e) {
            return IamDecision::deny('transport: '.$e::class);
        }
    }
}
