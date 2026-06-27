<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `iam.auth` (doc 06): garantisce che la richiesta abbia un utente autenticato risolvibile
 * a un subject IAM. Non sostituisce il guard di Laravel: lo presuppone, e fa fail-closed (401) se non
 * c'è un utente, così le route protette non passino mai con subject anonimo.
 */
final class IamAuthenticate
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            abort(401, 'Unauthenticated.');
        }

        return $next($request);
    }
}
