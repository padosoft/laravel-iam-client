<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\Deciders\CachingDecider;
use Padosoft\Iam\Client\Deciders\HttpDecider;
use Padosoft\Iam\Client\Deciders\LocalDecider;
use Padosoft\Iam\Client\Gate\IamGateAdapter;
use Padosoft\Iam\Client\Http\Middleware\IamAuthenticate;
use Padosoft\Iam\Client\Http\Middleware\IamCan;
use Padosoft\Iam\Contracts\Authorization\AuthorizationEngine;
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
        $package->name('laravel-iam-client')->hasConfigFile('iam-client');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Decider::class, fn (Application $app): Decider => $this->makeDecider($app));

        $this->app->singleton(IamClient::class, fn (Application $app): IamClient => new IamClient(
            $app->make(Decider::class),
            $this->config(),
        ));

        $this->app->singleton(IamGateAdapter::class, fn (Application $app): IamGateAdapter => new IamGateAdapter(
            $app->make(IamClient::class),
            $this->stringConfig('gate.intercept') ?? 'namespaced',
        ));
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
                $this->stringConfig('http.token'),
            )
            : new LocalDecider($app->make(AuthorizationEngine::class));

        if (!$this->boolConfig('cache.enabled', true)) {
            return $base;
        }

        $store = $app->make('cache')->store($this->stringConfig('cache.store'));

        return new CachingDecider($base, $store, $this->intConfig('cache.ttl', 30), true);
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

    private function intConfig(string $key, int $default): int
    {
        $value = $this->app->make('config')->get('iam-client.'.$key, $default);

        return is_int($value) ? $value : $default;
    }
}
