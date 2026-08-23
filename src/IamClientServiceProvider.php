<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Padosoft\Iam\Client\Auth\ClientCredentialsTokenProvider;
use Padosoft\Iam\Client\Auth\DelegatedTokenVerifier;
use Padosoft\Iam\Client\Auth\PrivateKeyJwtTokenProvider;
use Padosoft\Iam\Client\Auth\StaticTokenProvider;
use Padosoft\Iam\Client\Auth\TokenProvider;
use Padosoft\Iam\Client\Console\ManifestPushCommand;
use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\Deciders\CachingDecider;
use Padosoft\Iam\Client\Deciders\HttpDecider;
use Padosoft\Iam\Client\Deciders\LocalDecider;
use Padosoft\Iam\Client\Gate\IamGateAdapter;
use Padosoft\Iam\Client\Http\Middleware\IamAuthenticate;
use Padosoft\Iam\Client\Http\Middleware\IamCan;
use Padosoft\Iam\Client\Http\Middleware\IamCanDelegated;
use Padosoft\Iam\Client\Support\DelegatedBearerInspector;
use Padosoft\Iam\Contracts\Authorization\AuthorizationEngine;
use Padosoft\Iam\Contracts\Delegation\DelegatedAuthorizationEngine;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Service provider del client (doc 06). Sceglie il trasporto in base a `iam-client.mode`
 * (local in-process | http Admin API), lo avvolge nella cache, registra il Gate adapter e gli alias
 * middleware `iam.can`/`iam.auth`. Tutto fail-closed di default.
 */
final class IamClientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-iam-client')->hasConfigFile('iam-client')->hasCommand(ManifestPushCommand::class);
    }

    public function packageRegistered(): void
    {
        // Expose the resolved TokenProvider so the push command (and the app) can obtain a bearer.
        $this->app->singleton(TokenProvider::class, fn (Application $app): TokenProvider => $this->makeTokenProvider($app));

        $this->app->singleton(Decider::class, fn (Application $app): Decider => $this->makeDecider($app));

        $this->app->singleton(IamClient::class, fn (Application $app): IamClient => new IamClient(
            $app->make(Decider::class),
            $this->config(),
        ));

        $this->app->singleton(IamGateAdapter::class, fn (Application $app): IamGateAdapter => new IamGateAdapter(
            $app->make(IamClient::class),
            $this->stringConfig('gate.intercept') ?? 'namespaced',
            $this->stringListConfig('gate.app_keys'), // IAM-40: intercetta solo questi prefissi app (vuoto = tutte le namespaced)
        ));

        // Token delegati (claim act): introspection-mandatory. L'URL deriva da http.oauth_url
        // (o da base_url, sostituendo il prefix Admin API con /oauth). Fail-closed: senza URL
        // introspection il verifier nega — un token delegato non si autorizza mai dal parse locale.
        $this->app->singleton(DelegatedTokenVerifier::class, fn (Application $app): DelegatedTokenVerifier => new DelegatedTokenVerifier(
            new GuzzleClient(['timeout' => $this->intConfig('http.timeout', 5)]),
            new DelegatedBearerInspector,
            $this->introspectionUrl(),
            $this->stringConfig('http.client_id'),
            $this->stringConfig('http.client_secret'),
            $this->boolConfig('http.allow_insecure', false),
        ));
    }

    /** URL dell'introspection: oauth_url esplicito, altrimenti derivato da base_url. */
    private function introspectionUrl(): string
    {
        $oauth = $this->stringConfig('http.oauth_url');
        if ($oauth !== null) {
            return rtrim($oauth, '/').'/introspect';
        }
        $base = $this->stringConfig('http.base_url');
        if ($base === null) {
            return '';
        }

        // base_url tipico: https://iam.example.com/api/iam/v1 → https://iam.example.com/oauth
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/oauth/introspect';
    }

    public function packageBooted(): void
    {
        // Alias middleware drop-in (doc 07 §13). In un'app consumer (solo `-client`) `iam.can` è il
        // nostro. Nel monorepo/same-app dove c'è anche il server, l'alias admin `iam.can` esiste già:
        // NON lo si sovrascrive (romperebbe l'Admin API). Le route dell'app possono comunque usare la
        // classe middleware esplicita.
        $router = $this->app->make(Router::class);
        $existing = $router->getMiddleware();
        if (!array_key_exists('iam.can', $existing)) {
            $router->aliasMiddleware('iam.can', IamCan::class);
        }
        if (!array_key_exists('iam.auth', $existing)) {
            $router->aliasMiddleware('iam.auth', IamAuthenticate::class);
        }
        // PEP per rotte ad audience delegata (token con claim act): introspection + intersezione.
        if (!array_key_exists('iam.can.delegated', $existing)) {
            $router->aliasMiddleware('iam.can.delegated', IamCanDelegated::class);
        }

        if ($this->boolConfig('gate.enabled', true)) {
            $this->app->make(IamGateAdapter::class)->register($this->app->make(Gate::class));
        }
    }

    private function makeDecider(Application $app): Decider
    {
        $base = $this->stringConfig('mode') === 'http'
            ? new HttpDecider(
                new GuzzleClient(['timeout' => $this->intConfig('http.timeout', 5)]),
                $this->stringConfig('http.base_url') ?? '',
                $this->makeTokenProvider($app),
                $this->boolConfig('http.allow_insecure', false), // IAM-39b: guard the Bearer-carrying decision call
            )
            : new LocalDecider(
                $app->make(AuthorizationEngine::class),
                // Il PDP delegato esiste solo con il modulo -agents installato (same-app):
                // assente ⇒ null ⇒ le richieste delegate NEGANO (fail-closed), mai un
                // check single-subject implicito.
                $app->bound(DelegatedAuthorizationEngine::class) ? $app->make(DelegatedAuthorizationEngine::class) : null,
            );

        if (!$this->boolConfig('cache.enabled', true)) {
            return $base;
        }

        $store = $app->make('cache')->store($this->stringConfig('cache.store'));

        return new CachingDecider($base, $store, $this->intConfig('cache.ttl', 30), true);
    }

    /**
     * Token provider per l'HttpDecider: client_credentials auto-gestito (con auto-rotazione del secret via
     * self-fetch) se sono impostati client_id + client_secret; altrimenti il token statico (retro-compat).
     */
    private function makeTokenProvider(Application $app): TokenProvider
    {
        $clientId = $this->stringConfig('http.client_id');

        // private_key_jwt (RFC 7523): asymmetric, no shared secret. Highest precedence when a private key is set.
        $privateKey = $this->resolvePrivateKey();
        if ($clientId !== null && $privateKey !== null) {
            return new PrivateKeyJwtTokenProvider(
                new GuzzleClient(['timeout' => $this->intConfig('http.timeout', 5)]),
                $this->stringConfig('http.oauth_url') ?? $this->deriveOauthUrl(),
                $clientId,
                $privateKey,
                $this->stringConfig('http.private_key_kid'),
                $app->make('cache')->store($this->stringConfig('cache.store')),
                allowInsecureTransport: $this->boolConfig('http.allow_insecure', false), // IAM-39b
            );
        }

        $secret = $this->stringConfig('http.client_secret');
        if ($clientId !== null && $secret !== null) {
            return new ClientCredentialsTokenProvider(
                new GuzzleClient(['timeout' => $this->intConfig('http.timeout', 5)]),
                $this->stringConfig('http.oauth_url') ?? $this->deriveOauthUrl(),
                $clientId,
                $secret,
                $app->make('cache')->store($this->stringConfig('cache.store')),
                allowInsecureTransport: $this->boolConfig('http.allow_insecure', false), // IAM-39
            );
        }

        return new StaticTokenProvider($this->stringConfig('http.token'));
    }

    /** The ES256 private key for private_key_jwt: inline PEM in config, or a readable file path to one. */
    private function resolvePrivateKey(): ?string
    {
        $key = $this->stringConfig('http.private_key');
        if ($key === null) {
            return null;
        }
        if (!str_contains($key, 'BEGIN') && is_file($key)) {
            $content = file_get_contents($key);

            return is_string($content) && $content !== '' ? $content : null;
        }

        return $key;
    }

    /** Best-effort: dall'Admin API base (.../api/iam/v1) risale al prefisso OAuth /oauth sull'host. */
    private function deriveOauthUrl(): string
    {
        $base = rtrim($this->stringConfig('http.base_url') ?? '', '/');
        $host = preg_replace('#/api/iam/v\d+$#', '', $base);

        return rtrim(is_string($host) ? $host : $base, '/').'/oauth';
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = $this->app->make('config')->get('iam-client');
        if (!is_array($config)) {
            return [];
        }

        $out = [];
        foreach ($config as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->app->make('config')->get('iam-client.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function boolConfig(string $key, bool $default): bool
    {
        $value = $this->app->make('config')->get('iam-client.'.$key, $default);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private function stringListConfig(string $key): array
    {
        $value = $this->app->make('config')->get('iam-client.'.$key, []);
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            // Trim + scarta le stringhe vuote: "warehouse, billing" → ['warehouse','billing'] (senza
            // ' billing' che non matcherebbe mai un prefisso ability). '0' resta valido (confronto esplicito).
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->app->make('config')->get('iam-client.'.$key, $default);

        return is_int($value) ? $value : $default;
    }
}
