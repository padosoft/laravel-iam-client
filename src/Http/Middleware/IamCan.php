<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Padosoft\Iam\Client\IamClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `iam.can:<permesso>` (doc 07 §13) — rimpiazzo drop-in del `permission:` di Spatie.
 * Risolve il subject dall'utente autenticato, interroga il PDP e fail-closed: 401 senza utente,
 * 403 se IAM nega (o se serve uno step-up non soddisfatto). Forma: `iam.can:app:perm` oppure
 * `iam.can:app:perm,{routeParam}` per legare la decisione a una risorsa dalla route.
 */
final class IamCan
{
    public function __construct(private readonly IamClient $client) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $permission, ?string $resourceParam = null): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $context = [];
        if ($resourceParam !== null) {
            // Con route-model-binding $request->route() può restituire un Model, non una stringa:
            // se lo si scartasse, una check pensata come per-risorsa diventerebbe globale (over-auth).
            $resource = $request->route($resourceParam);
            $ref = $this->resourceRef($resource);
            if ($ref !== null) {
                $context['resource'] = $ref;
            }
        }

        if (!$this->client->can($user, $permission, $context)) {
            abort(403, 'This action is unauthorized.');
        }

        return $next($request);
    }

    private function resourceRef(mixed $resource): ?string
    {
        if ($resource instanceof Model) {
            $key = $resource->getKey();

            return is_scalar($key) ? (string) $key : null;
        }

        return is_scalar($resource) && (string) $resource !== '' ? (string) $resource : null;
    }
}
