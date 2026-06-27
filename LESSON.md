# LESSON.md — lezioni dell'ecosistema Laravel IAM

> Lezioni **generali** valide per ogni package, accumulate costruendo Laravel IAM v1.0 (16 milestone,
> TDD + loop advisory). Sotto, la sezione **specifica di questo package**. Aggiorna ad ogni scoperta.

## Generali — toolchain & PHPStan max

- **Test con PHP 8.5 (Herd)**: `~/.config/herd/bin/php85/php.exe`. Su Windows, PHPStan vuole
  `--memory-limit=1G` e, prima di Pest/testbench, `attrib -R` sulla dir
  `vendor/orchestra/testbench-core/laravel/bootstrap/cache` (bug `is_writable()`). `.gitattributes eol=lf`.
- **PHPStan crash transitorio** ("Result is incomplete because of severe errors"): ri-eseguire risolve.
- **Mai cast su `mixed`**: usare guardie `is_int`/`is_string`/`is_numeric`, non `(string)`/`(int)`.
- **`@property` sui Model invece di castare nel chiamante**: una colonna castata letta da un servizio
  esterno al model fa fallire PHPStan (`property.notFound` → `Cannot cast mixed`). Dichiarare
  `@property Carbon|null` sul model; poi un `?->` su valore ora non-null diventa `nullsafe.neverNull` → `->`.
- **Mai `*/` dentro un docblock**: `decided_*/granted_id` in `/** */` CHIUDE il commento → ParseError.
- **`@phpstan-impure`** per i metodi con side-effect osservabili (mutano una proprietà pubblica e vengono
  chiamati due volte): senza, PHPStan crede il secondo valore immutato (`booleanOr.leftAlwaysFalse`).
- **Config da `mixed` → `array<string,mixed>` provabile**: `is_array($x) ? $x : []` resta `array<mixed>`;
  ricostruire con un `foreach` che casta le chiavi a stringa per soddisfare la firma.
- **larastan + generics Eloquent + closure**: `Builder<User>` non è assegnabile a `Builder<Model>`
  (invariante) e `get()` perde `TModel`. Per un paginator generico: `@param Builder<covariant Model>` +
  `callable(Model): array` con narrowing `instanceof` al call-site.

## Generali — sicurezza & processo

- **Fail-closed sempre**: default-deny, deny-overrides; un errore (transport, PDP, parsing) → deny, mai un
  allow né un 500 opaco. Vale per PDP, client, directory, AI.
- **Il loop advisory trova bug reali ad ogni slice**: TOCTOU, fail-open, takeover, info-disclosure,
  escalation. `copilot -p` (advisory), **mai** `--autopilot --yolo`. Ogni fix → qui.
- **TOCTOU sulle transizioni di stato**: leggere-poi-scrivere uno stato senza `DB::transaction` +
  `lockForUpdate` + re-check sotto lock = last-write-wins (grant orfano, doppia approvazione).
- **Snapshot vs dato vivo**: la governance congela i segnali/policy al momento giusto; l'esito non deve
  dipendere da una modifica successiva (un ruolo tolto dal catalogo non deve creare grant permanenti).
- **Tenant isolation = 404, non 403**: il cross-tenant deve essere indistinguibile da "non esiste",
  altrimenti il 403 conferma l'esistenza dell'UUID (enumerazione).
- **Deps pesanti in `suggest`, non `require`**: `aws-sdk-php`, `ldaprecord` (ext-ldap), `laravel/ai`
  rallentano/ rompono install e CI. Il core resta usabile senza; l'adapter reale è opzionale e, se non
  installabile in dev, va isolato (sottospazio + `excludePaths` PHPStan).
- **Commit message via file** se l'here-string fallisce su Windows: scrivere su file e `git commit -F`.

## Specifiche di questo package (client)

- **Il transport non ha un fail-open.** `LocalDecider`, `HttpDecider` e il decorator `CachingDecider`
  negano su QUALUNQUE errore (eccezione del motore, HTTP non-2xx, body non-array, transport down). È una
  scelta deliberata: un PDP irraggiungibile non deve mai aprire le porte. Chi vuole tollerare un outage lo
  fa a livello applicativo, non nel transport. Test negativo obbligatorio per ogni decider.
- **`granted()` ≠ `allowed`.** `IamDecision::granted()` = `allowed && !requiresStepUp`. Un permit che
  richiede uno step-up (AAL più alto) NON è ancora consentito: middleware e Gate adapter usano `granted()`,
  mai `allowed` nudo, altrimenti una sessione a basso AAL passerebbe.
- **Collisione alias `iam.can`.** Nel monorepo/same-app il server registra già un `iam.can` (Admin API,
  `AuthorizeIamPermission`). Il client registra l'alias SOLO se assente (`!array_key_exists('iam.can', ...)`);
  altrimenti sovrascriverlo ha rotto 29 test dell'Admin API. Le route dell'app possono comunque usare la
  classe middleware esplicita (`IamCan::class`).
- **Collisione di helper nei test.** Una helper Pest `client()` ha fatto "Cannot redeclare function" con
  l'helper di un altro modulo: usare nomi specifici (`iamTestClient()`).
- **Route-model binding nel middleware.** Con `iam.can:perm,{param}`, `$request->route($param)` può tornare
  un Model (non una stringa): se lo si scartasse, una check per-risorsa diventerebbe globale (over-auth).
  `IamCan::resourceRef()` estrae la chiave dal Model (`getKey()`), altrimenti il valore scalare.
- **`cacheKey()` include TUTTI gli input.** La decisione dipende da subject, permission, org/app/resource,
  context ABAC e AAL: ometterne uno farebbe condividere l'esito tra query diverse (cache poisoning logico).
  Le query `explain` non si cachano (spiegazione fresca, non condivisibile tra contesti).
- **Mai loggare il Bearer dell'Admin API né il body di errore.** Gli errori di transport riportano
  `$e::class`, non il messaggio (che potrebbe contenere token/PII).
