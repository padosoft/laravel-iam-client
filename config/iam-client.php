<?php

declare(strict_types=1);

/*
 * Configurazione del client Laravel (doc 06/07). Un'app che consuma Laravel IAM delega le
 * decisioni di autorizzazione al PDP: in-process quando il server vive nella stessa app
 * (`mode=local`), via Admin API quando è remoto (`mode=http`). Fail-closed di default: un
 * errore di trasporto NON concede mai accesso.
 */
return [
    // 'local' = PDP in-process (AuthorizationEngine bindato dal server) | 'http' = Admin API remota.
    'mode' => env('IAM_CLIENT_MODE', 'local'),

    'http' => [
        'base_url' => env('IAM_CLIENT_BASE_URL'),     // es. https://iam.example.com/api/iam/v1
        'token' => env('IAM_CLIENT_TOKEN'),           // Bearer per l'Admin API
        'timeout' => 5,
    ],

    // Tipo di subject e applicazione/organizzazione di default per le query di decisione.
    'subject_type' => 'user',
    'default_application' => env('IAM_CLIENT_APP'),
    'default_organization' => env('IAM_CLIENT_ORG'),

    // Cache delle decisioni (deterministiche a parità di input). TTL breve: le decisioni cambiano
    // con i grant. Le query `explain` non si cachano mai.
    'cache' => [
        'enabled' => true,
        'ttl' => 30,        // secondi
        'store' => null,    // null = cache store di default
    ],

    // Gate adapter: registra Gate::before per delegare le ability a IAM.
    'gate' => [
        // Disattiva in shadow mode del bridge (vedi iam-spatie.mode): l'enforce del Gate adapter
        // corromperebbe il diffing shadow.
        'enabled' => true,
        // 'namespaced' = intercetta solo le ability con ':' (forma app:permesso), lasciando le Gate
        // locali invariate; 'all' = intercetta tutte le ability.
        'intercept' => 'namespaced',
    ],

    // Nota: il transport è SEMPRE fail-closed (un PDP irraggiungibile nega). Non esiste un opt-out
    // fail-open: tollerare un outage è una scelta applicativa consapevole, non del transport.
];
