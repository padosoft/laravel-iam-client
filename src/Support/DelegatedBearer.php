<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Support;

/**
 * Vista di un token DELEGATO (claim `act` presente): il subject delegante, la catena
 * di attori (attore corrente per primo, formato `agent:<id>`), la grant (`pds_dgr`)
 * e gli scope. Può nascere da un parse locale NON verificato (instradamento) o dai
 * claims dell'introspection (verità server-side): `verified` dice quale dei due.
 *
 * Regola PEP: un token delegato NON si autorizza mai dal parse locale — la decisione
 * usa SOLO la vista `verified` (introspection). Il parse locale serve a capire che
 * il token È delegato e va instradato di conseguenza.
 */
final readonly class DelegatedBearer
{
    /**
     * @param  list<string>  $actors
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $sub,
        public array $actors,
        public ?string $grantId,
        public array $scopes,
        public bool $verified,
    ) {
        if ($this->sub === '' || $this->actors === []) {
            throw new \InvalidArgumentException('DelegatedBearer richiede sub e almeno un actor.');
        }
    }
}
