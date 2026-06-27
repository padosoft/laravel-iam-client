# CLAUDE.md — laravel-iam-client

Guida per agenti AI che lavorano in questo repo (package dell'ecosistema **Laravel IAM**). Prima di
qualsiasi lavoro leggi `LESSON.md`, `RULES.md` e questa pagina. Skill: `laravel-iam-package-workflow`.

## Cos'è questo package

Client Laravel per app che **consumano** Laravel IAM: delega le decisioni di autorizzazione al PDP
centrale tramite middleware (`iam.can`/`iam.auth`), un Gate adapter e una cache delle decisioni.

- **Composer:** `padosoft/laravel-iam-client`
- **Namespace:** `Padosoft\Iam\Client\`
- **Ruolo nell'ecosistema:** è il **consumer SDK** — un'app Laravel installa questo package per delegare
  l'autorizzazione (e, a tendere, l'autenticazione OIDC) a un server Laravel IAM. Non contiene policy né
  storage: traduce le primitive Laravel (`Gate`, `$user->can()`, middleware) in query verso il PDP.
- **Dipende da:** `padosoft/laravel-iam-contracts` (per `AuthorizationEngine` in `mode=local`),
  `guzzlehttp/guzzle` (transport HTTP), `lcobucci/jwt`, `spatie/laravel-package-tools`. **Non** dipende da
  `-server`.

## Architettura del package

Tutto fail-closed. Sottocartelle di `src/` (namespace `Padosoft\Iam\Client\…`):

- **`Contracts/Decider`** — il seam del transport: `decide(DecisionRequest): IamDecision`. Il client di
  alto livello non sa quale transport sta usando.
- **`Deciders/`** — `LocalDecider` (delega in-process all'`AuthorizationEngine` del server quando vive
  nella stessa app: zero rete), `HttpDecider` (chiama l'Admin API `POST /decisions:check` con Bearer),
  `CachingDecider` (decorator: cache a TTL breve; **mai** le query `explain`). Tutti e tre fanno **deny su
  qualunque errore** (transport, HTTP non-2xx, body invalido, eccezione del motore).
- **`IamClient`** + **`Facades/Iam`** — la facciata applicativa: `can($user, 'app:perm', $context)` /
  `check(...)`. Estrae le chiavi riservate del context (`organization`, `application`, `resource`, `aal`,
  `explain`) e passa il resto come fatti ABAC. Senza subject risolvibile → deny (`no-subject`).
- **`Gate/IamGateAdapter`** — registra `Gate::before`; di default intercetta solo le ability *namespaced*
  (con `:`) e restituisce `null` sulle altre, così da non scavalcare le Gate/policy locali. L'esito IAM è
  vincolante (enforce) e fail-safe sullo step-up via `IamDecision::granted()`.
- **`Http/Middleware/`** — `IamAuthenticate` (`iam.auth`: 401 senza subject) e `IamCan`
  (`iam.can:perm[,routeParam]`: 401/403, con binding di risorsa dalla route, anche via route-model
  binding).
- **`DecisionRequest`** / **`IamDecision`** — DTO `final readonly`. `IamDecision::granted()` = permit **e**
  nessuno step-up pendente (interpretazione fail-safe usata da middleware e Gate). `cacheKey()` include
  TUTTI gli input (subject, permission, org/app/resource, context ABAC, AAL).
- **`IamClientServiceProvider`** — sceglie il transport da `iam-client.mode`, lo avvolge nella cache,
  registra il Gate adapter e gli alias `iam.can`/`iam.auth` **solo se non già presenti** (collisione con
  l'admin `iam.can` del server nel monorepo/same-app).

I docblock citano i doc di design `laravel-iam-docs/` (06 client, 07 enforce, 09 PDP/decision).

## Invarianti (NON violare)
1. **Mai bypassare il PDP.** L'AI propone draft/spiegazioni; il PDP deterministico decide allow/deny.
2. **Fail-closed** sull'autorizzazione; mai fail-open su operazioni critiche.
3. **Niente segreti/OTP/PII nei log.** Segreti cifrati via envelope encryption.
4. **Audit per ogni mutazione** (hash-chain).
5. **Slug permessi/ruoli immutabili** (`app_key:permission`).
6. **Scope/condition dichiarati dalle app** nel manifest, mai hardcoded nel core.
7. **Nessuna UI legge il DB**: solo Admin API.
8. **OIDC layer**: base MIT (steverhoades). **Vietato** codice AGPL (limosa-io). OAuth = league/oauth2-server.

### Specifiche di questo package
- **Il transport è SEMPRE fail-closed. Non esiste un opt-out fail-open.** Un PDP irraggiungibile nega; chi
  vuole tollerare un outage lo gestisce a livello applicativo, consapevolmente. Ogni nuovo decider/percorso
  deve mantenere questo invariante (test negativi obbligatori: transport error → deny).
- **`granted()`, non `allowed`, è la verità per gate/middleware.** Un permit che richiede uno step-up
  (`requiresStepUp`) NON concede finché l'AAL non è soddisfatto. Mai usare `allowed` nudo per consentire.
- **Alias middleware registrati solo se assenti.** `iam.can` collide con l'admin del server: nel monorepo
  registrarlo sovrascriverebbe l'Admin API. Verifica `!array_key_exists(...)` prima di aliasare.
- **Niente PII/token nei log.** Il Bearer dell'Admin API non va mai loggato; gli errori riportano la
  classe dell'eccezione (`$e::class`), non il messaggio che potrebbe contenere dati sensibili.

## Convenzioni codice
- `declare(strict_types=1)`, classi `final` di default.
- Namespace radice **`Padosoft\Iam\`** (PSR-4).
- **PHPStan max**, **Pest**, **Pint**. Test negativi obbligatori (denial, fail-closed, step-up non
  soddisfatto, alias-collision).

## Gate (in locale, con PHP 8.5 Herd)
```bash
# in un progetto root con questo package installato via path/VCS + le sue dev-deps
php vendor/bin/pint
php vendor/bin/phpstan analyse --memory-limit=1G
php vendor/bin/pest
```
> Nota: i test e il tooling QA sono stati sviluppati nel monorepo originale; vedi `LESSON.md` per il
> setup standalone. La suite di test completa di questo package è in fase di migrazione per-repo.

## Loop di lavoro
Branch per task → gate locale (test + advisory `copilot -p`, **mai `--yolo`**) → PR → CI + Copilot review
→ merge → tag. Aggiorna `LESSON.md` ad ogni fix. Dettaglio: la skill `laravel-iam-package-workflow`.
