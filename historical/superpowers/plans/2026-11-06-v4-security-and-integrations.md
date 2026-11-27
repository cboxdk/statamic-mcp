---
title: "V4 Security and Integrations"
description: "Implementation sequence, contract decisions, migration rules and verification gates for Statamic MCP v4"
weight: 3
---

# V4 Security and Integrations

Dato: 2026-11-06. Status: implementering påbegyndt på `sd/release/v4`. Retningen er aftalt; interfaces og migrationsregler er endnu ikke en frigivet API. Sikkerhedsrettelser og en intern kontrakt-/operationsprototype findes. Se [implementeringsstatus](2026-11-06-v4-implementation-status.md) for verificeret arbejde og resterende gates.

V4 skal give andre pakker en stabil integrationsflade med samme sikkerhedskontrol som de indbyggede handlinger. Første eksterne integration er valgfri telemetry. Leverancen omfatter også eksplicit adgang, sikre godkendelser og defineret adfærd ved gentagne writes.

Planen bygger på sikkerhedsgennemgangen, CMS-sammenligningen og den eksisterende security-review-hardening-worktree. Den historiske plan fra april beskriver v3’s stateless confirmations; den er ikke målet for v4.

## Aftalt retning og afgrænsning

- Offentlige kontrakter til pakkeintegrationer, registrerbare scopes og flere lifecycle-events.
- Fælles adgangskontrol for indbyggede tools, udvidelser, resources og eventuelle senere jobs.
- Valgfri integration med telemetry; ingen obligatorisk telemetry-afhængighed i MCP-kernen.
- Et major-skift til de nødvendige kompatibilitetsbrud. Versionsnummer og release oprettes først i den senere releaseproces.
- Kompatible sikkerhedsrettelser skal kunne leveres til v3 uafhængigt af v4.

Ikke i første leverance: SDK-udskiftning, generisk workflowmotor, selvstændig framework-pakke, MCP Apps, MCP Tasks, vilkårlig kodeeksekvering eller en ny LLM-klient. Det er ikke nødvendigt for at levere kontrakterne.

## Foreslåede beslutninger for 4.0

| Emne | Foreslået beslutning | Konsekvens |
|---|---|---|
| Transport | Behold Laravel MCP bag en adapter | Pakkekontrakter bruger ikke Laravel MCP request/response-typer |
| Autentificering | Fjern Basic Auth | HTTP-klienter skal bruge OAuth eller scoped Bearer-token |
| HTTP | Deaktiveret indtil eksplicit konfiguration | Installation eller migration vælger en profil og aktiverer endpointet |
| Stdio | Kræv valgt profil; ingen automatisk operator-adgang | Lokal administration er fortsat mulig via eksplicit operator-profil |
| Scopes | Registrerede navne og særskilt edit/publish/delete/admin | V3-write-scopes er ikke implicit en v4-publiceringstilladelse |
| Wildcard | Ingen dynamisk udvidelse til fremtidige capabilities | Et grant får et eksplicit, godkendt sæt capabilities |
| Godkendelse | Ejerbundet operation og atomisk engangsforbrug | Gamle stateless confirmation-tokens accepteres ikke |
| Menneskekrav | Autentificeret CP-godkendelse som første kanal | En modelstyret confirmation-runde er ikke menneskelig godkendelse |
| Katalog | Individuelle actions som anbefalet interface | Registry beholdes eksplicit, med samme autorisation og forsigtige annotations |
| Integrationer | Manglende policy/schema og navnekollision afvises | Installering af en pakke aktiverer ikke dens MCP-adgang |
| Telemetry | Eksplicit tilvalg, metadata-allowlist | Eksportfejl påvirker ikke udfaldet af en content-operation |
| DCR | Dokumenteret kompatibilitetsvalg | Fjernes ikke alene fordi en nyere protokol foretrækker CIMD; klientmatrix afgør behovet |

Read-only-profilen betyder ikke adgang til alle læsbare data. Users, submissions, logs, konfiguration og andre følsomme områder kræver særskilt tilvalg. Profiler begrænser, men erstatter aldrig brugerens rettigheder og grantets scopes.

Editor-profilen må oprette og ændre kladder inden for tilladte sites og collections. På et publiceret entry uden revisionsunderstøttelse afvises en almindelig editor-write: den må ikke stiltiende blive en live-opdatering. Ingen automatisk aktivering af Statamic Pro eller revisionsfunktionen. En særskilt live-write skal både være tilladt i profilen og have publiceringsgrundlag.

En menneskelig godkendelse udvider ikke grantets eller actorens rettigheder. Hvis en anden person godkender, skal både den udførende actor og godkenderen opfylde de relevante regler. Delegeret udførelse som en anden bruger er ikke en skjult del af godkendelsesflowet.

## Den offentlige kontrakt

Følgende navne er forslag. Frys kontrakterne efter to fungerende vertikale eksempler og en ekstern testpakke; undgå at love alle interfaces før det.

| Kontrakt | Ansvar | Må ikke |
|---|---|---|
| `ActionDefinition` | Identitet, version, schemas, scopes, effekt, policy og preview/idempotency-egenskaber | Indeholde mutable request-state |
| `ActionHandler` | Udføre valideret, autoriseret arbejde og returnere et defineret resultat | Være ansvarlig for at huske den obligatoriske pipeline |
| `ExecutionContext` | Actor, grant-reference, valgt site/profil, operation-id og deadline | Indeholde raw credentials eller global state fra tidligere kald |
| `CapabilityRegistry` | Registrere actions/resources/prompts, ejerskab og katalogrevision | Tillade overskrivning af kerne- eller andre pakkenavne |
| `ScopeRegistry` | Registrere scopes, labels og deres godkendte action-bindinger | Acceptere ukendte scopes eller give en ny action adgang gennem et gammelt grant uden vurdering |
| `Authorizer` | Kombinere profil, grant, Statamic-politik, site, ressource og felter | Lade en extension ophæve et kerneafslag |
| `OperationStore` | Atomisk claim, deduplikering, outcome og versionsbundne previews | Love atomisk commit på tværs af filer, database og eksterne services |
| `ApprovalStore` | Godkendelser med ejer, mål, digest, udløb og engangsforbrug | Behandle en approval-handle som selvstændig autorisation |
| `LifecycleObserver` | Modtage reducerede lifecycle-data | Ændre input, give adgang eller ændre et allerede gennemført udfald |

Registry-ejerskab er package-id plus action-id. Eksterne tool-navne genereres med MCP-kompatibelt format og kollisionskontrol. Registrering afsluttes ved boot. Hver server/app får sin egen registry; en langlivet worker må ikke dele mutable actor- eller request-data mellem kald.

Scopes beskriver et navn, men et grant skal også afgrænse de godkendte capabilities og ressourcer. En ny action knyttet til et eksisterende scope må ikke automatisk blive tilgængelig for gamle grants. Et snapshot af godkendte action-id’er/versioner eller en tilsvarende immutable capability-bundle skal bevises i kontraktprototypen. Cache-invalidation alene er ikke consent.

## Execution pipeline

1. Resolve transport, registreret action og autentificeret actor/grant. Ukendt action afvises.
2. Kontrollér profil, action-adgang, grænser og input-schema. Læk ikke detaljer om utilgængelige ressourcer gennem valideringsfejl.
3. Resolve den faktiske ressource og locale, og håndhæv Statamic-, ressource- og feltpolitikker. Afvis forsøg på at skrive beskyttede felter med en tydelig fejl.
4. Kontrollér operationens forventede version, argumentdigest, eventuel approval og idempotency. Også et cachet resultat kræver aktuel læseadgang og gældende outputfiltrering.
5. Claim operationen atomisk. Genvalidér de nødvendige mutable sikkerhedsforudsætninger ved write-grænsen; stop før første sideeffekt ved revoke, konflikt eller udløbet approval. Emit `ExecutionStarted` gennem isolerede observers før handleren.
6. Udfør handleren. Registrér `effects=none|committed|unknown`. Validér det lovede output-schema.
7. Gem outcome, forbrug approval efter den aftalte state machine, og anvend outputfiltrering. Et write efterfulgt af schemafejl er ikke en sikker invitation til at gentage operationen.
8. Emit reducerede terminale lifecycle-events gennem isolerede observers. Afvisninger tidligere i pipelinen udsender `ExecutionDenied`. Audit og telemetry følger hver sin dokumenterede fejladfærd.

En revoke skal forhindre nye operationer i at starte. Den kan ikke gøre allerede udførte writes ugjorte. For bulkoperationer defineres kontrolpunkter og delvise resultater. Planen lover ikke, at enhver igangværende filskrivning kan afbrydes atomisk.

Resource- og prompt-registrering skal bruge samme autorisationscontext. En resource må have en særskilt læsekapabilitet uden at aktivere schema-writes. Det bevarer den eksisterende adskillelse mellem blueprint-læsning og blueprint-redigering.

## Operationer og godkendelser

Foreslåede operationsstates: `prepared`, `awaiting_approval`, `ready`, `executing`, `succeeded`, `failed_no_effect`, `outcome_unknown`, `expired`, `cancelled`. Transitioner kræver compare-and-set eller tilsvarende atomisk låsning. Terminale states genåbnes ikke automatisk.

Approval har egne states: `pending`, `approved`, `claimed`, `consumed`, `rejected`, `expired`. Claim af operation og approval skal være koordineret. Crash mellem claim og kvittering må ikke gøre approval fri til et nyt write. Recovery må undersøge operationen og kræve ny vurdering ved ukendt udfald.

Bindings: actor-id, grant-id/revision, action-id/version, site, konkrete mål, inputdigest, expected resource version og udløb. Opbevar ikke Bearer-tokenet. Godkendelsesreferencen er opaque og kortlivet. CP-endpoints kræver authentication, relevant permission og CSRF-beskyttelse; GET må ikke godkende noget.

Idempotency-nøgler bindes til actor/grant, actionversion og argumentdigest. Samme nøgle med ændret input giver conflict. JSON-RPC request-id og correlation-id er ikke idempotency keys. Retention skal mindst dække den dokumenterede retry-periode; efter udløb må klienten ikke få et falsk løfte om deduplikering.

Storage-spike skal vælge et minimum: filbackend til én node med dokumenteret låsning og databasebackend til flere noder, hvis begge kan opfylde samme kontrakt. Multi-node-konfiguration med lokal lock-store afvises. Holdbare operationsdata må ikke afhænge af en evictable cache alene. Bevis crash-recovery og lock-udløb før write-migrationen.

Statamic-filændringer og databaseændringer udgør ikke én transaktion. For entries anvendes expected version og den relevante ressource-lås tæt på save. Flere ressourcer eller eksterne sideeffekter kræver eksplicit delvist/ukendt outcome; ingen implicit rollback-garanti.

## Eventkontrakt

Første event-sæt:

- `ExecutionStarted`, `ExecutionDenied`, `ExecutionCompleted`, `ExecutionFailed`.
- `ApprovalRequested`, `ApprovalConsumed`, `ApprovalRejected`.
- `GrantIssued`, `GrantRevoked`, `RefreshReplayDetected`.

Fælles envelope: event-schema-version, event-id, operation-id, correlation-id, tidspunkt, package/action-version, transport, normaliseret outcome/reason og eventuel duration. Ressourcekontekst er reduceret; eventdata indeholder ikke hele argumenter, resultater, exceptions eller credentials. Telemetry får en yderligere allowlist-projektion af den interne event.

`ExecutionStarted` udsendes først efter autorisation. `ExecutionCompleted` betyder, at handlerens kontrakt er opfyldt; det dokumenteres særskilt, hvilke sideeffekter der er holdbare. Afviste kald har deres egen event og må ikke ligne execution-success. Ved genaflevering af et gemt idempotent resultat må integrationen ikke tælle et nyt ressource-write.

Observers kan ikke veto operationer. Sikkerhedspolicies er synkrone og fejler lukket. Observerfejl isoleres, og en fallback rapporterer uden rekursivt at kalde den samme fejlede observer. Ved kølevering deduplikerer forbrugeren på event-id. Events lover ikke exactly-once.

Audit er særskilt: definer hvilke profiler der kræver holdbar audit før første write. Hvis audit efter sideeffekten fejler, rapporteres effekten sandfærdigt og outcome må ikke fejlagtigt være `failed_no_effect`. Auditkravet må ikke styres af telemetry-sampling.

## Telemetry som referenceintegration

Første integration eksporterer observationer; den giver ingen nye MCP-læsetools. Adapteren placeres som valgfri integration i telemetry-laget, med et eksplicit enable-flag. En selvstændig bridge-pakke oprettes kun, hvis versionsmatrixen kræver en særskilt releasecyklus.

- Én tool-operation span med korrekt HTTP-parent eller isoleret stdio-context. Content-events kan korreleres uden at tælle som ekstra tool-kald.
- Metrics bruger begrænsede action-, outcome- og transportlabels. Ingen actor-id, entry-id, URL eller correlation-id som metrics-labels.
- Producer kun tilladte metadata; redaktion før eksport er et ekstra værn. Ved redaktionsfejl droppes batchen, og ved eksportfejl ændres MCP-resultatet ikke.
- Inbound tracecontext er ikke identitet. Baggage begrænses, og caller-controlled sampling må ikke føre til ubegrænset dataopsamling.
- Minimumsrelease fastlægges fra faktisk udgivet telemetry-kode og kontrakttests. Et lokalt checkout er ikke en supporteret release.
- Læseadgang til telemetry planlægges separat med eget scope, projekt/site-binding, tidsvindue og resultatloft.

## Implementeringsrækkefølge

Hver række er en reviewbar leverance med egne tests. PR-numre tildeles først ved oprettelse. Alle navne er arbejds-id’er.

| ID | Leverance | Afhænger af | Exit-kriterium |
|---|---|---|---|
| V00 | Inventar og v3/v4-opdeling af eksisterende sikkerhedspatch | Ingen | Hver ændring har compatibility-vurdering, regressionstest og udpeget målbranch |
| V01 | Kontraktprototype og eksternt test-addon | V00 | Ét read og ét afgrænset write gennem samme pipeline; addon uden super/`*`; dokumenteret API-forslag |
| V02 | ScopeRegistry og grant/capability-model | V01 | Ukendte scopes og kollisioner afvises; ny pakke/action udvider ikke gammelt grant; consent og execution deler definition |
| V03 | Profiler og fælles authorizer | V02 | HTTP/stdio, site/locale, actor og feltpolitikker består positiv/negativ matrix |
| V04 | OperationStore, ressourceversioner og idempotency | V03 | Race- og crash-tests for begge valgte backends; sandfærdigt ukendt outcome |
| V05 | ApprovalStore og CP-godkendelse | V04 | Ejerbinding, CSRF, udløb, revalidation og atomisk engangsclaim; ingen model-bypass |
| V06 | Lifecycle-events og audit-semantik | V04, V05 | Event-schema-tests og observerfejl efter write giver korrekt resultat |
| V07 | Valgfri telemetry-adapter | V06 | Uden pakke, disabled, enabled og failure cases består; ingen følsom eksport |
| V08 | Migrér alle indbyggede actions og øvrige MCP-flader | V02–V06 | Coverage-inventar uden ubeskyttede legacy-paths; schemas og sikkerhed pr. action |
| V09 | Dry-run-migration, diagnose og upgrade-guide | V02, V03, V05, V08 | V3-fixtures migreres eller afvises tydeligt uden automatisk rettighedsudvidelse |
| V10 | Klientmatrix, kompatibilitet, performance og release review | V07–V09 | Alle release gates nedenfor er dokumenteret med faktisk resultat |

V01 er en prototype, som skal afsløre kontraktfejl før en stor migration. Migrér ikke samtlige routere, mens scope- og operationmodellen stadig ændres.

V08 deles i mindre ændringer: entries/revisions; assets; terms/globals; structures/blueprints; users/roles/groups; system/content-facade; resources/prompts/registry. Hver gruppe får en mapping fra gammel action til ny kontrakt, scopes, policies, outcome og test. Ingen handler erklæres idempotent, blot fordi den tidligere kunne kaldes to gange uden synlig fejl.

Forslag til kodeplacering: `src/Integration/Contracts`, `src/Integration/Registry`, `src/Execution`, `src/Authorization`, `src/Operations`, `src/Approvals`, `src/Events` og `src/Testing`. Tilpas til den endelige kontraktprototype; det er ikke et krav at oprette alle mapper eller et separat framework.

## V3-sikkerhedssporet

Opdel den nuværende ucommittede patch, før der kommer v4-refactors ovenpå. Opret en isoleret `sd/`-branch fra den verificerede v3-base til de kompatible rettelser; arbejdets nuværende branch og tests bevares.

Kandidater til backport: faktisk entry/site/author-autorisation, korrekt role/group-permission, hemmelighedsfiltrering, begrænset HTTP-download og passende HTTPS/Origin-kontrol. Hver kandidat vurderes mod v3’s dokumenterede klientadfærd; sikkerhedsrelevante stramninger skal stadig beskrives.

OAuth replay/revocation-rettelser skal undersøges separat for custom-driver-kompatibilitet og grant-levetid. De er ikke automatisk breaking, men heller ikke automatisk kompatible. Default-off eller fjernelse af Basic Auth, nye scopebetydninger, ny driverkontrakt og nye profiler hører til v4. Minimumsversioner begrundes konkret i nødvendige sikkerhedsrettelser og supporteret dependency-matrix.

Der tagges eller publiceres ikke noget som del af denne plan. Den senere release følger repositoryets releaseprocedure.

## Migration og rollback

Foreslåede kommandoer: en read-only diagnose og en separat migration med `--dry-run` som standard. Endelige navne vælges i V09. Rapporten viser token/grant-id’er og berørte capabilities, aldrig plaintext tokens eller passwords.

| Eksisterende tilstand | V4-adfærd |
|---|---|
| Basic Auth-klient | Migrér til OAuth/Bearer før skift; ingen automatisk password-to-token-konvertering |
| Afgrænset read-token | Bevar kun ved entydig mapping til godkendte actions, ressourcer og profil; ellers kræves valg |
| `entries:write`, brede content-scopes eller `*` | Kræv eksplicit vurdering af edit/live/publish/delete/admin; ingen stiltiende udvidelse |
| OAuth-grant med ændret betydning | Ny godkendelse; gamle refresh-grants må ikke genskabe de gamle brede rettigheder |
| Custom OAuth-driver | Skal bestå den nye obligatoriske kontraktsuite, før v4 aktiveres |
| Custom BaseRouter-extension | Adapter kun hvis komplet policy/schema/scope-definition kan valideres; ellers blokeres den med migrationsdiagnose |
| Gammel confirmation-token | Ugyldig; operationen skal forberedes og godkendes på ny |
| Lokal stdio-konfiguration | Vælg profil eksplicit; gamle operator-rettigheder aktiveres ikke ved autodetektion |
| Telemetry installeret | Ingen automatisk eksport eller MCP-læseadgang; adapter kræver tilvalg |

Migrationen skal kunne gentages uden dubletter og stoppe før mutation ved unsupported store/driver. Opbevar en migrationjournal og en beskyttet backup af relevante konfigurations- og storage-data; ændr ikke CMS-content som en sideeffekt af adgangsmigration.

Rollback er en procedure, ikke kun `composer require` af en gammel version. V3 må ikke læse nye grants eller approval-records med forkert betydning. Bevar rollback-data isoleret, tilbagekald v4-grants ved rollback, og genskab aldrig tidligere tilbagekaldte tokens eller replaybare approvals fra backup. Indhold, som faktisk er ændret, rulles ikke automatisk tilbage sammen med pakken.

## Verifikation

Planen genbruger eksisterende testerfaring, men den tidligere grønne sikkerhedssuite er ikke bevis for nye interfaces. Nye tests skal gennem rigtige Statamic actors og policies frem for mocks, der kun implementerer `isSuper()`.

Obligatoriske gates:

1. Kontrakttests for extension-registrering, namespaces, scope-binding, schemas og resultater. Syntetisk addon med eget scope og begrænset bruger.
2. Direkte tools/call, registry-execute, resources og private prompts afprøves med samme permissionmatrix. Skjulte capabilities kaldes direkte for at bevise afvisningen.
3. Grants afprøves før/efter ny pakke, ny action, ændret rolle, revoke og siteændring. Ingen adgangsudvidelse uden ny godkendelse.
4. Revisions-enabled/disabled og Pro/non-Pro: redaktører kan arbejde med kladder; live-writes kræver korrekt tilladelse; ingen regression af eksisterende working-copy-semantik.
5. Parallel approval/replay/revoke og duplicate-write testes med reelle samtidige processer og atomiske stores. Ingen parallel fuld Statamic-testsuite med delte fixtures.
6. Crash før write, efter write og før kvittering giver de dokumenterede states. Retrying et ukendt outcome udfører ikke automatisk igen.
7. Observer og exporter kaster efter write; content-resultat og audit forbliver sandfærdige. Redactorfejl eksporterer ikke data. Ingen rå exceptions eller credentials i events.
8. Personlige kataloger/resources har privat cache og korrekt invalidation. Cachehits giver ikke adgang efter revoke, og workers lækker ikke context mellem brugere.
9. Wire-tests for de protokolrevisioner, vi faktisk understøtter, og reproducerbare klientversioner. Generiske MCP-conformance-tests suppleres med rigtige klientforløb for OAuth, tools og approvals.
10. PHP/Laravel/Statamic-matrix, fil/database-stores og telemetry-versionsmatrix; relevant SEO Pro-suite køres med pakken installeret. Oversprungne tests skal være synlige i releasevurderingen.
11. Baseline og måling af response-størrelse, query-/storage-operationer og peak-memory. Når telemetry er fraværende/disabled, må der ikke ske exporter-I/O. Fastlæg konkrete performancebudgetter efter V01’s baseline, ikke med opdigtede tal.
12. PHPStan niveau 9, formattering, dependency-audit, migrationsfixtures og manuel sikkerhedsreview af de endelige writes og grants.

## Supplerende krav til sikkerhed og performance

Disse krav udvider exit-kriterierne for V01–V10. De opretter ikke nye features eller leverancer. Observationerne nedenfor er fra den nuværende worktree; mulige følgevirkninger skal efterprøves med målrettede tests.

### Deadline og sandfærdigt udfald

`BaseStatamicTool::runToolCall()` måler i dag varigheden efter `executeInternal()` og kan derefter returnere en timeout-fejl. Det afbryder ikke arbejdet og kan klassificere et gennemført write misvisende. Grænsen for responsstørrelse kontrolleres tilsvarende på et allerede opbygget svar; den er ikke en grænse for arbejdet eller allokeret memory.

V04/V08 skal skelne mellem deadline, afbrudt arbejde og langsomt gennemført arbejde. Brug monotont ur til forløbet tid, og propagér et resterende budget til kontrollerbare HTTP-, storage- og bulkoperationer. Kontrollér deadline før næste sideeffekt, og rapportér faktisk effektstatus ved overskridelse. En hård worker-/proxy-timeout kan stadig efterlade ukendt udfald; det skal OperationStore håndtere. Undgå at love sikker præemption af vilkårlig PHP-kode.

Test: en handler gemmer og overskrider derefter fristen; resultatet må ikke blive en retrybar fejl, der påstår, at intet skete. Test også stop før første write og et afbrudt response-stream efter et gennemført write.

### Ressourcebudgetter før dyrt arbejde

V01/V03/V08 skal definere grænser for request-bytes, JSON-dybde, antal inputfelter, batch-størrelse, fan-out, antal berørte ressourcer og output pr. action. Håndhæv transportgrænser før eller under parsing, hvor runtime tillader det; en reverse-proxy-grænse dækker ikke stdio. Tilføj særskilt admission/concurrency-grænse pr. principal og installation, så lovlige kald ikke kan optage alle workers. Afvis overskydende arbejde tidligt med dokumenteret backoff.

Et registry-kald med mange indre actions skal betale for og begrænses efter det faktiske arbejde, ikke tælle som ét billigt HTTP-kald. Propagér samme budget til indre kald og afvis rekursiv dispatch, som omgår begrænsningerne. Begræns også dynamisk schema-opbygning; eksterne schema-referencer må ikke udløse vilkårlig netværksadgang.

Brug afgrænsede forespørgsler og stabil pagination før serialisering. Undgå at hente hele datasættet for derefter at skære resultatet ned. Benchmarks skal dække store collections, multisite og dybe Bard/Replicator-data samt p95/p99, peak-memory og storage-operationer ved realistisk samtidighed. Grænser og tærskler fastlægges fra baseline før release, ikke ud fra en enkelt lille fixture.

### Låse uden unødig blokering

`OAuthMutationLock` bruger aktuelt én nøgle, `statamic-mcp:oauth-mutations`, med 120 sekunders lease og op til 10 sekunders ventetid. Den serialiserer også uafhængige grants. Det er en enkel sikkerhedsbarriere, men en mulig flaskehals; langsomt arbejde inde i låsen kan øge ventetiden for andre klienter.

V02/V04 skal måle contention og vælge dokumenteret låsegranularitet og rækkefølge. En simpel ændring til en lås pr. grant er utilstrækkelig, hvis revoke-all samtidig skal koordinere alle brugerens grants. Udgående metadata-fetches bør ligge uden for den kritiske sektion, hvor det kan ske uden at bruge forældet autorisationsstate; genvalidér derefter før mutation.

Test lease-udløb, to workers, revoke-all mod refresh, deadlocks og processtop. En worker, der mister sin lease, må ikke fortsætte med en autoritativ mutation uden en mekanisme, der afviser dens forældede ejerskab. Optimér ikke låsen ved at fjerne den sikkerhed, den leverer.

### Begrænset lagring og oprydning

V04–V07/V09 skal fastlægge retention og byte-/antalgrænser for operationer, gemte resultater, approvals, replay-markører, audit og eventuelle telemetry-spools. Oprydning skal være inkrementel og må ikke scanne eller omskrive hele storage under hvert tool-kald. Test stor historik, fuld disk, unavailable store og manglende scheduler.

Sikkerhedstilstand må ikke blive ugyldig på en måde, der genåbner adgang: aktiv replay-detektion bevares gennem grantets relevante levetid, og idempotency-records bevares gennem den lovede retry-periode. Uafklarede operationer skal fortsat kunne identificeres, selv hvis et stort resultats body er slettet. Følsomme previews/resultater får adgangsbeskyttelse, dataminimering og eksplicit retention. Der indføres ikke en ny ekstern arkivservice som del af dette krav.

### Cache og driftskonfiguration som sikkerhedsgrænser

V03/V08/V10 skal verificere cache-nøgler og invalidation for grant, policy-revision, site/locale og capability-revision. En cache må hverken lække data eller bevare en tilbagekaldt adgang. Bevar målrettet cache-invalidation; nye observers må ikke udløse gentagne fulde Stache-opbygninger for hver ressource i et bulk-kald. Test også context-cleanup efter exceptions i langlivede workers og secret rotation uden genbrug af gamle godkendelser.

V09’s diagnose skal kontrollere canonical URL/HTTPS, trusted-proxy-konfiguration, Origin-allowlist, storage/lock-egnethed og farlige development-undtagelser. Caller-controlled forwarding-, correlation- og traceheaders er ikke identitet eller autorisation. Stdio må kun skrive protokoldata på stdout; diagnostik skal til stderr eller den valgte logkanal. Test misformede headers, proxy-scenarier og fejlede observers uden credentials i output.

Udvidelser skal bruge samme URL-fetch-beskyttelse som kernen, når de tilbyder caller-styrede fetches. Pakkeinstallation er fortsat betroet PHP-kode, ikke en sandbox. Begræns eksponerede capabilities og verificér dependency-/versionstøtte i den eksisterende release-gate.

Disse krav supplerer MCP’s råd om credential-binding, state handles og SSRF; deres konkrete budgetter og runtime-semantik er vores eget designansvar. [MCP Security Best Practices](https://modelcontextprotocol.io/docs/2026-07-28/tutorials/security/security_best_practices).

## Statamic-kontrakter, databevarelse og cache-korrekthed

Gennemgået 2026-11-06 mod installeret Statamic **6.40.0** og MCP-worktreens aktuelle kode. Nedenstående er kildekodefund og konkrete acceptkrav; de er ikke nye runtime-rettelser eller en påstand om fuld dækning af alle routers. Bekræft fejlene med reproducerende tests før rettelser. Sikkerhedsrettelser, som kan leveres kompatibelt, hører også til V00 og skal ikke vente på v4.

### Konkrete huller i den aktuelle kode

| Prioritet | Observation og kilde | Konsekvens og nødvendig verifikation |
|---|---|---|
| Høj | `GlobalsRouter::getGlobal/updateGlobal` bruger permission-navnet `edit {handle} globals`, men kalder ikke Gate på de resolvede variables. `RouterHelpers::checkWebPermissions` kontrollerer heller ikke site. Statamics `GlobalSetVariablesPolicy::view/edit` kontrollerer netop det konkrete site, og CP-controlleren autoriserer de lokaliserede variables. | MCP mangler CP's site-afgrænsning på denne sti. Reproducer med en rigtig bruger, tilladt global-set og kun ét tilladt site; både læsning og skrivning i det andet site skal afvises. Test også custom Gate/policy-afslag. |
| Høj | Entries-, terms- og globals-writes ignorerer flere steder returværdien fra `save()`/`delete()`. Statamics Entry, Term og Variables kan returnere `false`, når en creating/saving/deleting-listener afviser. CP's global-controller undersøger save-resultatet. | MCP kan melde `updated/deleted=true`, selv om en integration blokerede ændringen. Test rigtige veto-events og efterfølgende frisk læsning. Behandl eksplicit `false` som afvist; kræv ikke universelt `=== true`, da Variables også kan returnere objektet ved succes. |
| Høj | `EntriesRouter::updateEntry` gemmer entry før `CollectionTreeEditor::move`; cachebehov registreres først efter flytningen. Et saved-event kan også kaste efter repository-write, før handleren når registreringen. `BaseStatamicTool::finally` tømmer kun allerede registrerede cachebehov. | En fejl kan efterlade delvist gennemførte writes og manglende MCP-oprydning. Statamics egne events kan have invalideret noget, men det er ikke en garanti. Fejlinjektion efter hver sideeffekt skal bevise korrekt outcome og oprydning uden automatisk gentagelse. |
| Mellem | `ClearsCaches::clearStatamicCaches` ignorerer Artisan-exitkoden og mærker et kald `cleared`, hvis det ikke kaster. Resultatet fra den udskudte flush indgår ikke i det returnerede tool-resultat. | En fejlet purge kan fremstå gennemført. Test nonzero exit, exception og manglende queue-worker. Skeln mellem gemt indhold og cache-status; en purge-fejl må ikke blive en invitation til at gentage write. |
| Mellem | `HandlesRevisions::saveAsWorkingCopy` gendanner in-memory-state efter flere kald, som kan kaste, uden `finally`. Slug/dato er allerede ændret på entry før helperens snapshot. Flere revisionsmetoder anvendes via antagelser om konkret Entry, selv om `Contracts\Entries\Entry` ikke deklarerer dem. | Undersøg delt objekt-state, afvist validering og alternative repositories. Test at live-data, slug, dato og working copy er korrekte efter både succes og fejl i samme worker. Det er endnu ikke dokumenteret som vedvarende datatab. |

### V01/V03/V08: Autorisation på Statamics faktiske objekter

- Lav en matrix pr. action: MCP-scope, policy ability, resolved objekt, site, feltpolitik og indirekte berørte objekter. Brug CP-controller og policy som reference sammen; et permission-navn er ikke tilstrækkeligt. Bevar MCP's yderligere begrænsninger oven på Statamic.
- Dæk entries og revisions, localized terms, global variables, navs/trees, collections, assets/containers, users og submissions. Oprettelse skal kontrollere parent/container/site før objektet eksisterer. Listings, antal og pagination må ikke afsløre utilgængeligt indhold.
- Reorder, move, delete og konfigurationsændringer kan påvirke børn, routes, relationer og flere sites. Autorisér hele effekten. Eksempel: Statamics entry-delete kan flytte child pages i collection-træet; kontrollér den indirekte effekt og dokumentér den i preview.
- Test custom policies og `Gate::before/after`, almindelige brugere og superbrugere, site-begrænsninger samt rettighedsændringer mellem preview og udførelse. Et superuser-resultat fra Statamic ophæver ikke grantets scopes eller MCP-feltpolitik.

### V01/V04/V08: Datakontrakt og sikre delvise ændringer

- Definér forskellen mellem udeladt felt, `null`, tom streng, tom liste og eksplicit fjernelse. Opdateringer skal bevare udeladte data, også ukendte/addon-ejede felter og nested værdier. En generisk rekursiv merge er ikke korrekt for ordnede lister eller Bard-dokumenter.
- Test Bard/Replicator/Grid/Group med stabile set-id'er, rækkefølge, deaktiverede sets, relationer, assets og SEO Pro. Beskriv eksplicit replace versus merge; afvis tvetydige/destruktive ændringer før write. Brug rå lagringsdata og fieldtypes' process-kontrakt; augmented output må ikke skrives tilbage som storage-format.
- Test multisite-origin, inheritance, localized overrides og genoptaget synkronisering. En ændring i ét site må ikke gøre nedarvede værdier til permanente overrides ved et uheld. Skeln mellem sletning af localization og hele ressourcen.
- Validering og preview skal arbejde på isoleret kandidat-state. Et afslag må hverken ændre filer/database eller efterlade ændrede objekter i Stache/Blink/worker-state. En vilkårlig TypeError i et addon må ikke stiltiende svække den lovede validering; eksisterende fallback til kun incoming fields kræver specifikke kompatibilitetstests og en begrænset, dokumenteret beslutning.
- Optimistisk versionering skal også opdage konkurrerende CP-, queue-, import- og addon-writes. MCP-låse beskytter ikke mod aktører, der ikke bruger dem. Test to samtidige redaktører og data+tree-ændringer; lov ikke atomiske multi-fil-writes eller rollback uden understøttelse fra det konkrete repository.
- Klassificér false-return, fejl før persist, fejl efter persist og delvis flertrinsændring særskilt. Native Statamic-events må fortsat kunne veto før persist; MCP's ikke-blokerende observers må ikke forveksles med dem. Et veto beviser ikke, at vilkårlig tredjepartskode ikke selv har udført sideeffekter.
- Brug Statamics facade/repository-contracts som indgang. Dokumentér yderligere krav til capabilities som revisions og tree-versionering; afvis en unsupported capability tydeligt før write. `CollectionTreeEditor` bruger aktuelt filsti og filhash, hvilket ikke alene dokumenterer støtte for et alternativt tree-repository. Test reelt understøttede drivere; et interface-typehint er ikke kompatibilitetsbevis.

### V04/V06/V08/V10: Cache-invalidering som en del af write-kontrakten

- Opret en action-matrix med berørte cachelag: request/Blink, Stache/repository-indekser, structures/URI, static HTML, REST/GraphQL, search og eventuelle eksterne caches. Markér hvad Statamics events dækker, hvad MCP skal supplere, og hvad der kræver deployment-konfiguration. Lov ikke automatisk CDN-purge uden integration.
- Bevar rettelsen for #53: fuld Stache-clear må ikke køre midt i et forløb, som stadig læser træer og strukturer. Deduplikér oprydning ved en sikker afslutningsgrænse, også ved delvis persist eller kastende listeners. Registrering af nødvendigt oprydningsarbejde skal overleve fejl efter første write.
- Test gamle og nye URL'er efter slug, mount, route, parent, site, publish/unpublish og delete. Test afhængige sider, ikke kun ressourcens egen URL. Collection-konfiguration kræver særskilt opmærksomhed: den installerede static-cache subscriber lytter ikke på `CollectionSaved`; den eksisterende målrettede invalidator må ikke forsvinde i refaktoreringen.
- Eksponér særskilt cache-udfald, fx complete/pending/failed, sammen med operationens persist-udfald. Genforsøg purge uden at gentage write. Test køforsinkelse, køfejl, nonzero Artisan-exit og fuld storage. Unpublish og adgangsændringer kræver en defineret maksimal periode, hvor gammelt publiceret indhold kan ligge i cache.
- Round-trip-tests skal verificere både rå persistent data, frisk repository-læsning og relevante frontend/API-resultater med varm og kold cache. En spy, som kun ser `Artisan::call`, beviser ikke, at siden er korrekt eller data bevaret.

### Versionsvalg og afslutningskrav

Statamics [officielle release notes](https://www.statamic.com/release-notes) angiver rettelser for tabt static-cache URL-tracking under samtidighed i 6.36, parallel Stache-warming i 6.39 samt REST/GraphQL-invalidering under samtidighed, nye structured-entry-URL'er og cold-Stache-clear i 6.40. Det gør disse versioner relevante for vores testmatrix. Den foreslåede minimumsversion 6.35.1 indeholder ikke alle rettelserne. **Anbefaling: afprøv 6.40 som v4-minimum**, og beslut derefter, om en ældre understøttet version kan dokumenteres sikker med egne afgrænsede værn; ændr ikke Composer-kravet alene på baggrund af release notes.

V10 er først færdig, når ovenstående fund har reproducerende tests og afklarede rettelser, permissions er verificeret med rigtige brugere, og data/cache-matricen passer på både minimum og nyeste understøttede Statamic. Kør tests sekventielt med de eksisterende delte Statamic-fixtures. De tidligere grønne tests dokumenterer ikke automatisk disse nye fejlsituationer.

## WebMCP: valgfri CP-adapter, ikke et krav til 4.0

Vurdering 2026-11-06: WebMCP er relevant for en agent, der hjælper en redaktør i det åbne kontrolpanel. V4 skal kunne genbruge action-kontrakterne fra en sådan adapter, men første release afhænger ikke af browserunderstøttelse eller et WebMCP-interface. Der implementeres ingen browseradapter som del af denne planopdatering.

### Verificeret status

[Web Machine Learning Community Groups draft fra 2. november 2026](https://webmachinelearning.github.io/webmcp/) beskriver JavaScript-tools på websider. Det er ikke en færdig W3C-standard. Den aktuelle API bruger `document.modelContext`, registrering med `registerTool` og en AbortSignal til registreringens levetid. Browseren bestemmer, hvordan tools præsenteres for agenten; API'en er ikke vores HTTP/stdio MCP-server. Ældre eksempler med `navigator.modelContext` må ikke bruges som kontraktgrundlag uden versionskontrol.

[Chromes officielle oversigt](https://developer.chrome.com/docs/ai/webmcp) beskriver både imperative JavaScript-tools og deklarative HTML-forms samt en origin trial fra Chrome 149. Understøttelse og API-version skal kontrolleres ved en prototype; der loves ikke generel browserkompatibilitet. Almindelig CP-brug skal fungere uden WebMCP, uden polyfill og uden aktivering.

### Foreslået afgrænsning og genbrug

- En senere opt-in CP-integration kan starte med ét afgrænset read/preview af det valgte entry og dets blueprint. Ingen automatisk eksport af hele action-registret, ingen offentlige frontend-tools og ingen publish/delete/admin som del af første forsøg.
- Adapteren oversætter schema og resultat, mens serveren genbruger authorizer, validering, operationer, Statamic-events, cache-arbejde og audit. Lokal formularudfyldning skal beskrives som en ændring af den åbne formular; den må ikke rapporteres som gemt indhold. Dirty formularer må ikke overskrives eller gemmes automatisk.
- V01 skal holde actor, credential-type og autorisationsgrundlag adskilt. En CP-session er ikke et OAuth-grant og må ikke få et syntetisk wildcard. Før adapteren får write-adgang, kræves en serverhåndhævet, eksplicit afgrænset delegation til agenthandlinger med action/site/ressource, udløb og revocation. Manglende delegation betyder afslag, ikke brugerens fulde rettigheder. Den præcise mekanisme besluttes først ved en adapterprototype.
- Browseren må ikke indeholde et langsigtet MCP-token for at få integrationen til at fungere. CP-endpoints bruger serverens sessionskontrol, CSRF og aktuelle Statamic-politikker ved hvert kald; et klientangivet actor-, site- eller capability-felt er ikke autorisationsbevis. Audit skelner mellem sessionens bruger, transport og verificeret autorisationsgrundlag uden at foregive verificeret agentidentitet.

### Sikkerheds- og lifecycle-krav til en senere prototype

[Chromes sikkerhedsvejledning](https://developer.chrome.com/docs/ai/webmcp/secure-tools) beskriver prompt injection og hints som `readOnlyHint`, `untrustedContentHint` og `consequentialHint`. De er signaler til klienten, ikke adgangskontrol eller garanti for godkendelse. Vores anbefaling er derfor:

- Content og addon-data er ubetroede input, også i CP. Returnér kun nødvendige felter; udled ikke instruktioner, tool-navne eller rettigheder fra redaktionelt indhold. Følsomme data, tokens og private felter må ikke lande i tool-metadata eller resultater.
- Ingen cross-origin/frame-eksponering som default. Test preview-frames og tredjepartsindhold særskilt. Browserens origin-grænse erstatter ikke serverens objekt- og site-policies, og WebMCP løser ikke XSS i kontrolpanelet.
- Afmeld sidens tools ved navigation, logout og ændret entry/site; genvalidér server-side også ved stale calls. Test flere faner, browser-back, sessionudløb og samtidige menneske/agent-redigeringer. Feature detection må ikke skjule fejl eller ændre almindelige CP-flows.
- Abort af et browserkald eller lukning af fanen er ikke rollback af en server-write. Et senere write-forsøg skal bruge samme operationsstatus, versionskontrol og idempotency som remote MCP.
- En synlig fane, et klik på en CP-knap, CSRF-token eller `consequentialHint` beviser ikke, at et menneske godkendte. Hvis en handling kræver menneskelig godkendelse, skal V05 dokumentere en mekanisme med den nødvendige sikkerhed mod agentbetjening. Indtil den findes, må en browseradapter ikke tilbyde disse handlinger.
- Mål både CP-load/registreringsomkostning og backend-kald. Registrér kun kontekstrelevante tools og hent data ved behov; undgå eager eksport af blueprints/content. En mulig gevinst ved færre UI-trin er ikke dokumentation for lavere backend-forbrug.

### Polish af V05: godkendelse er ikke automatisk menneskebevis

V05's eksisterende CP-flow giver autentificering, binding og CSRF-beskyttelse. Det dokumenterer ikke alene det tidligere formulerede mål om ingen model-bypass. Tilføj en separat adversarial test, hvor en browseragent kan betjene samme CP-session og forsøger at godkende sin egen operation. Skeln mellem almindelig brugerbekræftelse og en godkendelse, der kræver et menneske; beskriv garantien præcist i kontrakt og UI. Ingen runtime-garanti må baseres på, at agenten forventes at lade knappen være.

Beslutning til v4: behold transportuafhængige kontrakter og få autorisationsgrundlaget korrekt nu. WebMCP forbliver en senere, afgrænset integration. V00's permissions-, databevarelses- og cache-rettelser har højere prioritet end adapteren.

## Punkter som prototypen skal afgøre

Disse er tekniske exit-kriterier, ikke blokeringer for at begynde V00/V01:

- Kan filbackend levere den nødvendige claim/recovery-semantik uden en skjult databaseafhængighed?
- Skal capability-snapshots ligge direkte på grants eller referere immutable bundles, og hvordan begrænses store grant-payloads?
- Hvilke eksisterende actions kan tilbyde ærlig preview/idempotency, og hvilke kræver et eksplicit mere begrænset interface?
- Kan adapteren registreres i statamic-telemetry uden uhensigtsmæssig versionskobling?
- Hvilke konkrete klienter kræver fortsat DCR eller registry-mode?
- Kan output- og transportkontrakten udvides i Laravel MCP uden fork eller private API’er?

## Næste konkrete arbejde

- [ ] V00: lav komplet ændringsinventar og separate v3/v4-mål uden at ændre runtime-adfærd.
- [ ] V01: skriv ADR for ActionDefinition, registry, scope/grant-binding og execution boundary.
- [ ] V01: implementér et read-eksempel og ét kladde-write som intern prototype, plus et syntetisk addon.
- [ ] Review prototypens API og migrationskonsekvenser, før alle routere flyttes.
- [ ] Fortsæt V02–V10 efter de angivne afhængigheder og exit-kriterier.

Ingen checkboxes markeres som udført alene fordi analyse eller designtekst findes. V4 er klar, når implementation, migration og verification gates faktisk er gennemført.
