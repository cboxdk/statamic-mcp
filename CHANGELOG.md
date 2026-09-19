# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.1.0] - 2026-09-19

Six issues reported against 3.0.0, all with root-cause analysis good enough to go
straight to the fix. Thanks to @JorisOrangeStudio and @revans-premier-education.

### Security

- **`generate` no longer bypasses the write gates** (#56) — `statamic-blueprints generate` calls `Blueprint::save()`, but the action appeared in neither write-action list, so it was gated as a *read*: a token holding only `blueprints:read` could create blueprints on disk, and a site with `resources.write => []` for blueprints could not stop it. It now requires `blueprints:write`, passes the resource policy in write mode, and sits behind the confirmation gate alongside `create`.

  **This will refuse calls that previously succeeded.** That is the point — but if you have automation generating blueprints with a read-scoped token, it needs `blueprints:write` now.

### Fixed

- **Writes no longer clear the Stache, which had been corrupting live sites** (#53) — Every write ended in a full Stache and static cache wipe, run through `Artisan::call` *inside the request*, which resets the in-memory stores halfway through a tool call. On a live multisite with a structured collection that left the tree repository returning nothing for the site; `CollectionStructure::validateTree()` then padded the empty tree with every entry at root in Stache order, so nested URLs flattened and — with `root: true` — a random entry became the homepage. Twice in one day, each time within minutes of a batch of MCP writes.

  Stache clearing is now off: `save()` updates the store and its indexes, which is why a Control Panel save clears nothing. **Static clearing stays on**, and that separation is deliberate. Dropping it too looked right — `StaticCaching\Invalidate` subscribes to the saved events — but its coverage has holes: no `CollectionSaved` or `TaxonomySaved` subscriber at all, `LocalizedTermSaved` rather than the `TermSaved` a newly created term dispatches, and on a slug change the new URL is invalidated while the old one keeps serving its cached page. A stale page is a bug visitors see, and a static clear is a rebuild rather than corruption. Both are configurable, and collection configuration writes invalidate their own URLs directly regardless. The line is **content versus structure**, not "never clear". Statamic keeps its stores current on save, but it does not rebuild the indexes that *depend* on a schema or configuration change: a relationship field's `max_items` leaves an index full of arrays that later throws a `TypeError` on `where()`; a collection's taxonomies leave `whereTaxonomy()` returning entries it no longer has; its mount leaves the old entry URIs indexed so every entry 404s; toggling `dated` leaves stale date indexes; a group's roles leave its members' role indexes behind. So **structural writes — blueprints, collections, taxonomies, navigations, global sets, users, roles, groups — always rebuild the Stache**, and that is not configurable. Content writes, which are the frequent ones and the ones that caused the incident, do not. The `statamic-system` `cache_clear` action is untouched — that clear was asked for. Diagnosed in full by @JorisOrangeStudio

- **Blueprint resources are no longer switched off by the writable tool** (#54) — `statamic://blueprints` was gated on `tools.blueprints.enabled`, the switch that turns off a tool which can create, update and delete blueprints. Sites keeping their content model in Git turn it off for exactly that reason, and doing so removed the only read-only way for an agent to learn a blueprint's fields — while the server's own instructions tell it to read the blueprint before every write. Reads now have their own switch, `resources.enabled`.

  The permission check was also inconsistent with the tool it mirrors: the resource required `configure collections` or `configure taxonomies` while `BlueprintsRouter` requires `configure fields`. It now accepts any of the three, and `resources.require_statamic_permission => false` lets a site authorize schema reads by token scope and resource policy alone, for editors who hold no `configure` permission

- **Resource refusals no longer arrive as HTTP 500** (#55) — laravel/mcp's `ReadResource` is not `Errable`, so anything a resource returned through `Response::error()` became JSON-RPC `-32603` and HTTP 500. Through a hosted client behind a proxy that surfaced as a bare 502 with the message gone, making "you lack permission" and "wrong URI" indistinguishable from an outage — and nothing reached Sentry either, since no exception was thrown. Expected conditions now travel as a normal result carrying `{success: false, error, code}`, the same way tool errors always have, so the reason survives the round trip

- **`content_validate` no longer fails every dated entry** (#57) — The entry sweep validated `['slug' => ..., ...data()]`. The slug is folded in because it lives outside `data()`; the entry date lives outside it in exactly the same way — a filename prefix on the file driver, a column on the Eloquent one — but was not, so the `date` field Statamic injects into a dated collection's blueprint was always missing and every entry reported a violation

- **`content_validate` no longer fails single-item relationship fields** (#58) — A relationship field with `max_items: 1` is stored as a bare string, not a one-element array, and the sweep ran the blueprint's rules against stored values without the fieldtype pre-processing the Control Panel does first. The generated `array` and `max:1` rules therefore both failed on every correctly stored row. A stored scalar on a relationship field is now wrapped before validation, and the value then goes through `preProcessValidatables()` so each fieldtype presents it in the shape its own rules were written against. Deliberately narrow: putting the whole record through `preProcess()` also fixes the shape, but launders the data with it — `Field::preProcess` substitutes `defaultValue()`, so a required field that is missing validates clean; `Integer::preProcess` casts `"not-a-number"` to `0`; a grid with `min_rows` invents a placeholder row. Every one of those is a false negative in the one check that exists to find drift.

  Together with #57 this had made `content_validate` unusable as a post-import check on an ordinary blog collection: every row failed on its date, and every row with an author failed twice more

### Added

- `cache.clear_stache_after_write`, `cache.clear_static_after_write` and the `resources.*` block, both documented in the [configuration reference](docs/configuration/reference.md)

### Internal

- **The test suite stopped leaking blueprints into `vendor/`** — `PreventsSavingStacheItemsToDisk` stops Stache writes, but a saved blueprint is a real file under Testbench's skeleton app, which survives every run. They had accumulated to 3,431 directories and 4,122 files that every `Blueprint::in()` listing had to scan. Worse than slow, it was misleading: a new test asserting that a refused write left no blueprint behind failed because an identically named file from an earlier run was still there — which looks exactly like the fix not working. `TestCase` now sweeps the directory once per process

## [3.0.0] - 2026-09-16

### Changed

- **`laravel/mcp` is now `^1.0` only** — 1.0 replaces the `initialize` handshake with `server/discover`, removes session tracking, and adds header validation on every POST, so supporting 0.x alongside it would mean carrying two protocol behaviours indefinitely. Nothing in this package needed rewriting for it: PKCE with S256 and Client ID Metadata Documents, which 1.0 makes mandatory, were already implemented and tested here. `src/Testing/FakeTransport.php` was updated to the new `Transport` contract — `send()` lost its `$sessionId` argument and `sessionId()` is gone.

  **Legacy clients still connect.** A client that sends `initialize` with protocol 2025-06-18 or 2025-11-25 negotiates as before and skips header validation entirely; there is a test pinning that, because it is the compatibility promise most likely to be broken by accident.

- **The tool catalog is partial by default** — Only `statamic-entries`, `statamic-blueprints` and `statamic-system-discover` are listed when a client connects. The other eight are exposed through `search_tools` and `execute_tools`, built into laravel/mcp.

  The reason is token cost, measured rather than assumed: `tools/list` was 31,077 bytes — roughly 7,800 tokens of JSON schema that every client loaded on every connection before it had asked anything, because eleven routers with action-based schemas are individually large. It is now 12,614 bytes, a 59% cut.

  **Nothing is restricted by this.** `ToolSearch` honours `shouldRegister()`, so a domain disabled in config stays invisible, and `execute_tools` invokes the tool's own `handle()` — token scope, resource policy, Statamic permissions and the confirmation gate all apply exactly as on a direct call. A searchable tool is hidden, never ungated.

  **This changes what a client sees, so it can regress a client that handles `search_tools` badly.** Set `STATAMIC_MCP_SEARCHABLE_CATALOG=false` to list every tool directly. The server's instructions describe whichever shape is active, so an agent is never told to use a route that is turned off.

  One consequence worth knowing: `execute_tools` emits progress notifications before its result, so those calls come back as an SSE stream rather than a single JSON body.

### Added

- **Cache hints on the read-only surfaces** — laravel/mcp 1.0 lets a response tell the client how long it may be reused. `server/discover`, `tools/list` and `prompts/list` are hinted at 5 minutes: they derive from config, so they change on deploy, not during a session. The two blueprint resources carry their own 1-minute hint. That is short on purpose — this addon can itself edit a blueprint, and an agent holding a stale schema across its own edit is the one failure the hint could cause. Everything else keeps the library default of "do not cache", and tool calls are never cacheable, which is what keeps content reads fresh.

  Scope is `private` throughout and must stay so: these responses are filtered by the caller's token scope, resource policy and Statamic permissions, so a shared cache could otherwise hand one caller's view to another.

- **`tests/Feature/WebEndpointProtocolTest.php`** — The web endpoint driven the way a real client drives it: over HTTP, through CORS, transport checks, bearer auth, throttling, the permission gate and 1.0's `ValidateMcpHeaders`. That layer had no coverage at all, which is how a protocol change of this size could have broken every client while the suite stayed green. Covers the 2026-07-28 handshake, the catalog split and its opt-out, cache hints, legacy `initialize` clients, and the `-32020` rejections for a missing or mismatched `Mcp-Method` / `Mcp-Name` header.

### Fixed

- **CORS preflight requests no longer 404, so browser-based clients can connect** — `Mcp::web()` registers GET, DELETE and POST but no OPTIONS route, so a preflight never reached `HandleMcpCors` and its entire preflight branch was dead code. Since `Authorization` alone is enough to trigger a preflight, no cross-origin browser client could connect to this endpoint at all — a pre-existing bug that 1.0 only makes more visible, since its mandatory `MCP-*` headers guarantee a preflight. The addon now registers its own OPTIONS route, deliberately outside the auth stack because a preflight carries no credentials and a 401 fails it exactly as surely as a 404 did. `Access-Control-Allow-Headers` also gained `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name`: a browser blocks a request carrying a header the preflight did not allow, while omitting them earns `-32020`, so a client without them is wedged either way. `HandleMcpCors` also moved to group middleware wrapping `Mcp::web()`: it used to be appended as route middleware, which runs *after* the `ValidateMcpHeaders` that `Mcp::web()` attaches itself, so a `-32020` rejection reached the browser with no `Access-Control-Allow-Origin` and was hidden behind a generic network error rather than showing why it was refused

- **Tools reached through `execute_tools` keep the configured response budget** — `ToolSearch` caps output at `mcp.tool_search.max_output_bytes` (65,536 by default), unrelated to this addon's `security.max_response_size` of 100,000, and it measures each result as `{content, structuredContent}` — both of which our tools fill with the same envelope, so the payload counts about twice. A response returned happily on a direct call would therefore come back as `OutputLimitExceeded` once its tool moved behind the searchable catalog, and raising `max_response_size` would not have helped because it is a different key. The library's budget is now derived from the addon's ceiling at three times its size — `structuredContent` holds the envelope and `content[0].text` holds the same envelope already serialized, so encoding escapes every quote and backslash a second time, and a quote-heavy 84 KB envelope measures 180 KB. Escaping can at most double the text copy, so tripling bounds one maximum-size response by construction. A value an operator set themselves is left untouched

- **`LargeDatasetTest` no longer fails at random, and now catches what it claims to** — The three scaling tests compared wall-clock timings, which say nothing about an algorithm on a machine running anything else: under load these operations were observed swinging from 6 ms to 710 ms for identical work, and a prune of 50 tokens timed *slower* than a prune of 500. Worse, the small runs were sub-millisecond, so the 0.01s floor turned the comparison into "the large run must finish within 200 ms" — an absolute performance assertion, not a scaling one. They now measure CPU time, which a competing process cannot inflate; across repeated runs at load average 112 it stayed within ~5% while wall-clock varied 100x.

  Fixing the flakiness exposed that the tests were also toothless: deliberately reintroducing the quadratic index rewrite moved the measured ratio only from 1.35x to 2.12x, well inside tolerance, because scanning and unlinking N token files is linear work in both implementations and dwarfs the index writes. The invariant is now asserted by **counting** index writes instead of timing them — pruning or deleting a batch must rewrite the index once, not once per token. The same reintroduced bug moves that count from 1 to 50, so the guard is exact and has no timing component at all

- **`FileTokenStore` scans with `scandir()` instead of `glob()`** — `glob()` bypasses stream wrappers entirely and silently returns nothing when the store is pointed at a wrapped path, which is what made the index-write counting above impossible to observe. Same ordering and same results; it simply no longer restricts the store to unwrapped local paths

- **The dashboard no longer breaks when a non-domain key is added under `tools`** — `StatsService::getToolCount()` type-hinted every value under `config('statamic.mcp.tools')` as an array, so the first key that was not a domain block threw a `TypeError` and took the whole Control Panel page down with it. Found by adding exactly such a key during this release. Non-domain entries are now skipped rather than assumed away; the offending setting also moved to its own `catalog` config block, where it belongs

## [2.10.1] - 2026-09-12

### Fixed
- **`create` no longer drops fields for every collection after the first** (#52) — `Entry::blueprint()` memoizes the resolved blueprint in Blink under `entry-{$this->id()}-blueprint`, and an unsaved entry has no id, so every new entry shared the key `entry--blueprint`. A web request creates at most one entry and never notices; this server is long-lived, so the second `create` in a different collection was handed the *first* collection's blueprint. `sanitizeIncomingFieldData()` then filtered the payload against it and every field the two blueprints did not share was silently discarded — from a write that returned `"success": true` and echoed back data already stripped, so a client could not detect it. The blueprint is now pinned to the collection before it is resolved, through Statamic's own fluent setter, which clears that key while leaving the normal resolution path — and any `EntryBlueprintFound` listener that injects fields — intact. `update` was never affected: it resolves from a saved entry, which has a real id. Terms are not affected either, since `Term::id()` is `{taxonomy}::{slug}` rather than null, and there is now a test holding that true. Reported by @idotter with a complete root-cause analysis
- **`template` and `layout` can be written on an entry** — Both are ordinary entry data — `Entry::template()` and `Entry::layout()` fall back to `$this->get(...)` — but neither has to be a blueprint field, and most blueprints do not declare one. The write pipeline runs values through `Fields::addValues()->process()->values()`, which only knows blueprint handles, so a caller setting a per-entry template got a successful response and an unchanged entry. Both keys are now carried through `create`, `update` and `localize`, and a non-string value is refused rather than stored. A blueprint that does declare the field is untouched and keeps going through the normal pipeline. The list is closed at these two: carrying every unrecognised key back would reintroduce exactly the silent junk writes `reject_unknown_fields` exists to stop, and `parent` is excluded on purpose because `Entry::parent()` derives from the structure tree rather than entry data, so storing it would be inert

## [2.10.0] - 2026-09-10

### Added
- **`localize` action on `statamic-entries`** (#48) — Creates an entry's localization in another site through Statamic's `makeLocalization()`, so the origin is set and untranslated fields keep falling back to it, and a structured collection places the new entry in the target site's tree. Only the fields actually sent are stored: taking everything `Fields::addValues()` populates would write an explicit null for each field a translator left alone, which both defeats the fallback and poisons later updates, since `update` validates stored data merged with incoming. It is a write action in every sense the authorization stack cares about — it carries `entries:write`, a write-mode resource policy check, and the `create {collection} entries` permission
- **`merge_sets` on `statamic-entries update`** (#47) — A top-level replicator field in `data` is merged into the stored array by item `id` instead of replacing it: an existing id is replaced in place, a new one appended, and stored items the caller did not send are left untouched. Changing one section of a page builder no longer means reading and resending every other section. Opt-in and off by default; removing and reordering still take a full-array write, where the intent is unambiguous
- **`field` path on `statamic-blueprints get`** (#51) — Scopes the response to one field or set via a dot path (`page_builder.ContentSection.media`). A page builder's format spec is proportional to every set it can hold, so the full response on a real blueprint runs to hundreds of kilobytes and cannot be returned at any useful depth; almost always the caller wants one component. An unresolvable path lists the valid segments at the level it failed, so a client can walk down without guessing
- **`FieldtypeExtensions` registry** (#46) — Statamic's fieldtype set is open but this package's is closed, so a fieldtype an addon provides got no wire-format guidance and no input coercion — the client guessed at the shape and the guess reached a `process()` written for Control Panel input. A site or addon can now register a spec resolver and an input sanitizer per fieldtype handle from a service provider. Without a registration nothing changes. See `docs/extending/fieldtypes.md`
- **Configurable response size limit** (#49) — `security.max_response_size` (`STATAMIC_MCP_MAX_RESPONSE_SIZE`), default unchanged at 100000 bytes, `0` to disable. The ceiling that matters is the client's context window, which differs per client and grows over time
- **Icon field format spec** (#43) — An icon field stores a bare name from a registered icon set, and those names live in the set's directory on disk rather than in the blueprint, so `icon` previously fell through to "this is a string". The spec now reports the resolved set and its names, listed once per response under `blueprint.icon_sets` rather than inlined on every field that uses the set — on a page builder with 24 icon fields sharing one 454-name set that is ~19KB instead of ~160KB. An unregistered set degrades to a named string spec rather than breaking the whole blueprint response
- **`reject_unknown_fields`** (#45) — Refuses a write carrying a key that is not a field handle inside a replicator set, grid row, bard set or group. `Replicator::processRow()` and `Grid::processRow()` merge the raw row back over the processed one, so such a key is written to the content file as inert data no template reads, and the write reports success — undiagnosable for a client that cannot read the blueprint from the repository. The error names the valid handles at that level. Not applied at the top level of a record, where non-blueprint keys such as `template`, `layout` and `parent` are legitimate.

  **This changes write behaviour and is on by default.** A caller that has been sending a stray key inside a set was silently writing junk and being told it succeeded; it now gets an error instead. That is the point of the change, but it will surface on upgrade rather than at the moment the junk was written. Set `STATAMIC_MCP_REJECT_UNKNOWN_FIELDS=false` to restore the old behaviour while you fix the caller

### Fixed
- **Select options are read in every shape Statamic accepts** (#50) — `selectSpec()` handled a flat list and a key => label map, but not the list of `['key' => ..., 'value' => ...]` maps that the Control Panel actually writes, which it dropped entirely: any `select`, `radio`, `button_group` or `checkboxes` field configured through the CP reported `allowed_values: []`, telling a client nothing about what it was allowed to send. All three shapes `Fieldtypes\HasSelectOptions::getOptions()` accepts are now read
- **The link fieldtype spec described references that do not resolve** (#44) — It advertised `statamic://entry/<uuid>`, which is Bard link-mark syntax. `ResolveRedirect`, which backs the link fieldtype, does not understand it and stores the value verbatim as a dead link. The spec now describes what actually resolves — plain URLs, `entry::<id>`, `asset::<container>::<path>` and `@child` — and warns against the scheme it used to recommend
- **`pruneExpired()` and `deleteForUser()` on the file token store were quadratic** — Both removed tokens from the hash index one at a time, and each removal re-read, re-decoded, re-encoded and rewrote the whole index under an exclusive lock. Pruning n tokens therefore wrote on the order of n² bytes; at 500 tokens that was ~12MB of index writes to delete a 50KB file's worth of entries. The removals are now batched into a single locked read-modify-write, which roughly halves prune time at 500 tokens and makes the cost genuinely linear. This is also what made `LargeDatasetTest` fail intermittently on CI: the quadratic term stayed invisible on a fast local disk and dominated on a contended runner

### Changed
- **Scaling stress tests take the fastest of several runs** — `LargeDatasetTest` timed a single run of each operation and compared 50 tokens against 500. A benchmark can only be made slower by interference, never faster, so the minimum of several runs is both the closest estimate of real cost and far steadier than one sample. Combined with the prune fix above, this ends a flakiness that had failed CI on three separate pushes

## [2.9.1] - 2026-09-04

### Fixed
- **Asset field values are round-trip safe** (#41) — `get` returns an assets field the way Statamic stores it, as a container-relative path (`icons/heart.svg`), but both the validation rules and the fieldtype pipeline expect the form the Control Panel submits, the asset ID (`assets::icons/heart.svg`). Sending a value straight back to `create`/`update` therefore failed file rules such as `mimes` — `MimesRule` does an `Asset::find()` on the value and a bare path finds nothing — and on rule-free fields it would have broken later in `Assets::process()`, which calls `Asset::findOrFail()`. Incoming asset paths are now resolved to canonical IDs before validation, in nested replicator, grid, group and bard set fields as well as top-level ones, and in the entry's stored data that an update merges in — so an unchanged asset field elsewhere in the blueprint no longer fails an update that never touched it. Values that resolve to no asset are left alone so validation still reports the real problem instead of silently dropping content
- **`content_validate` no longer flags every valid assets field** — The read-side sweep ran the blueprint's rules against stored values, which meant it reported up to three errors for a perfectly valid single-file assets field: "must be a file of type: svg" (the file rules resolve the value with `Asset::find()`, which a bare path misses), plus "must be an array" and "must not have more than 1 items" from the fieldtype's own rules, which expect a list. Asset references are now bridged to canonical IDs for the rule pass, through nested replicator, bard, grid and group fields as well as top-level ones. The structural pass still sees the stored values verbatim, so a `missing_asset` finding keeps quoting the reference as it appears on disk
- **`content_validate` resolves single-container asset fields** — An assets field that omits `container` where the site has exactly one was skipped by the missing-asset check; it now resolves the same way the fieldtype does

## [2.9.0] - 2026-08-27

### Added
- **`content_validate` action on `statamic-content-facade`** — Validates content that is *already stored* against its blueprints. Writes through this addon are validated on the way in; content that arrives another way (git merges, hand-edited YAML, blueprints changed after the content was written) was previously invisible. Each record gets two passes: the blueprint's own validation rules, evaluated the same way a Control Panel save evaluates them, plus structural checks the rule engine cannot express — replicator/bard blocks naming a set that no longer exists, sets and grid rows storing keys the blueprint dropped, `select`/`radio`/`button_group`/`checkboxes` values outside the declared options, assets fields pointing at missing files, and navigation items linking to deleted entries. All of these pass rule validation silently while breaking at render time. Supports `scope`, `collection`/`taxonomy` filters, `severity` filtering, offset/limit paging across the combined record stream, and a `max_findings` cap that keeps summary counts accurate when the list is truncated
- **`ValidatesContentRecords` concern** — The two-pass record validation above, extracted so other routers can reuse it. The rule pass is isolated per record: a malformed stored value that makes a fieldtype's rule builder throw is reported as a `rule_engine_error` warning rather than aborting the sweep, and the structural pass still runs to name the underlying shape problem
- **MCP resources for blueprints** — The server now exposes the `resources` capability, which it previously left unused. `statamic://blueprints` lists every readable blueprint with its URI; `statamic://blueprints/{namespace}/{handle}` returns one blueprint's fields, so a client can look up field structure to shape a write without spending a tool call. Because `RequireMcpPermission` defers scope checks to the primitive, resources run the same four gates a router read does — tool enablement, token scope (`blueprints:read`), resource policy, and Statamic permissions — via a new `AuthorizesResourceAccess` concern. Blueprints the resource policy hides are absent from the index, not merely refused on read
- **`#[Title]` on every tool** — `tools/list` has always carried a `title` field; without the attribute it fell back to `Str::headline(class_basename())`, so clients displayed "Entries Router". Tools now declare their own display titles

- **Typed validation model** — `Finding`, `RecordRef`, and the `Severity`/`FindingType`/`RecordType` enums replace the `array<string, mixed>` bags the validation sweep threaded through its call chain. Severity is derived from the finding type rather than passed alongside it, so a finding cannot be built with a severity that contradicts what it describes; findings become arrays only at the MCP response boundary
- **`Testing/InteractsWithMcp` and `Testing/FakeTransport`** — Shipped testing helpers for driving this addon's MCP server, dogfooded by the package's own suite. A `tests/Fixtures` composition site is included in the PHPStan paths so the traits are analysed where they are actually mixed in — which immediately caught a wrong `class-string` bound in the trait itself
- **Supply-chain gate** — `bin/check-licenses.php` fails the build on any non-permissive dependency (SPDX dual-licensing handled: a package passes if any arm is permissive), and `bin/generate-sbom.php` emits a deterministic CycloneDX 1.5 `sbom.json` with sorted components and a content-derived serial number, so it only changes when dependencies do. Wired into CI along with `composer audit --no-dev`, plus a new `composer qa` aggregate. CI validates the generated document rather than diffing it against the committed one: `composer.lock` is gitignored because this is a library, so a freshly resolved lock legitimately differs and a drift check would fail whenever any transitive dependency publishes. The license check found one real case: `statamic/cms` is proprietary, recorded as a justified exception because a Statamic addon cannot avoid depending on Statamic
- **`SECURITY.md`** — Private vulnerability reporting via GitHub, an explicit split between what this addon secures and what the operator does, and a limitations section stating plainly that the audit log is append-only by convention with no hash chain (neither tamper-proof nor tamper-evident), that confirmation tokens are replayable within their window, and that `require_https` falls back to off when the published config predates the key

### Fixed
- **The MIT license text actually ships** — `composer.json` has always declared MIT, but the repository never contained a `LICENSE` file, so the grant existed only as metadata. The standard MIT text is now included
- **Entry updates no longer fail on blueprints with a required slug** (#39) — The #27 fix removed the slug from the validated payload entirely, so Statamic's default slug field (`validate: [required, UniqueEntryValue…]`) could never be satisfied: every update failed with "The Slug field is required", whether the caller omitted the slug or resent the current one. The entry's effective slug is now injected back into the validation payload, and both `FieldsValidator` invocations (including the `TypeError` fallback) resolve the `UniqueEntryValue({collection}, {id}, {site})` placeholders via `withReplacements()`, so the rule excludes the entry being updated — the false positive #27 was about — while a slug owned by another entry is still rejected
- **`date` no longer has to be resent on every update of a dated collection** — Like the slug, the date is an entry property absent from the merged data payload, so a blueprint with a required date field failed any update that did not repeat a date the caller never meant to change. The entry's current date now satisfies the rule when the payload omits it
- **Explicit slug on create actually works** — `createEntry()` read `$arguments['slug']`, but the tool schema never declared the parameter, so no client could send it and the slug was always derived from the title. The schema now declares `slug`, and create also accepts it as `data.slug` — the shape update uses — storing it as an entry property in both cases, never as a data key
- **PHPStan level 9 is no longer partly disabled** — The config carried blanket `ignoreErrors` patterns (`#Method .* should return .* but returns mixed#`, `#Cannot call method .* on .*\|null#`, `#Parameter .* expects .*, mixed given#`, and four more) that suppressed whole error classes across `src/`, so "level 9 clean" meant considerably less than it sounded. Removing them surfaced 54 real errors — almost all method calls on `Blueprint|null`, `Entry|null`, or `GlobalSet|null` after a lookup, because `requireResource()` returned an error array without narrowing the variable. Every site now checks for null explicitly (identical messages, identical behaviour, and the analyser can see it), the untyped Statamic/Eloquent return values are narrowed rather than cast, and the four `@phpstan-ignore` annotations on `abort()` calls are gone. `requireResource()` itself is removed, having no callers left
- **CI verifies formatting instead of rewriting it** — The Tests workflow ran Pint in fix mode, committed the result, and pushed it back to the branch; it now runs `pint --test` and fails on violations, matching what the release workflow already does
- **CI exercises both Laravel majors** — `composer.json` claims Laravel 12 and 13 via `orchestra/testbench: ^10.0 || ^11.0`, but the matrix only varied PHP, so every job resolved testbench 11 and the Laravel 12 claim was never verified. The matrix now spans both. The suite passes on Laravel 12
- **Package classes are no longer `final`** — Nine classes were sealed, blocking consumers from extending or decorating what the package ships
- **Failed tool calls set the MCP `isError` flag** — Every response was returned via `Response::structured()`, which never marks an error, so a failed call arrived at the client indistinguishable from a successful one; only a model parsing the JSON body would notice `"success": false`. Failures are now assembled from `Response::error()` plus the same structured content, so both the protocol flag and the full envelope (including confirmation tokens) survive
- **Plaintext credentials no longer reach stack traces** — `TokenService::validateToken()`/`findByPlainText()`, `ConfirmationTokenManager`'s token methods, `AuthenticateForMcp::authenticateWithCredentials()`, and every `ClientConfigGenerator` method took secrets as plain parameters. In the stdio server, `setupErrorHandling()` writes `getTraceAsString()` to stderr, so a throw anywhere in those call chains logged the bearer token verbatim. All are now marked `#[\SensitiveParameter]`, matching the hardening laravel/mcp applied upstream in v0.9.0
- **Server version no longer hardcoded** — `StatamicMcpServer::$version` was the literal `'2.8.0'` and would have drifted at the next release. It is read from Composer's installed-package metadata, falling back to `0.0.0` only when that is unavailable
- **Release workflow no longer hangs** — The release job ran `pest --parallel`, which is not parallel-safe: `Statamic\Testing\AddonTestCase` points every Stache store, and `PreventsSavingStacheItemsToDisk`'s `dev-null` directory, at one shared `tests/__fixtures__` path, so ParaTest workers deleted each other's fixtures. This produced ~34 spurious failures or, when workers collided on the file-store `flock()` calls, a hang that burned the 6h job timeout (v2.6.1 and v2.8.0 both died this way, and both releases had to be published by hand). The release job now runs the same single-process `pest` that gates pull requests, and every job has an explicit `timeout-minutes` so a hang fails in minutes instead of hours
- **Release notes are no longer empty** — The changelog extraction matched `[v2.8.0]` against headings written as `[2.8.0]`, so it never selected anything. The tag's `v` prefix is now stripped, with a fallback message if the section is missing
- **Release workflow verifies formatting instead of rewriting it** — The `Fix code formatting` step ran Pint in fix mode and discarded the result; it now runs `pint --test` and fails on violations

### Changed
- **Docs follow the standard topic layout** — `introduction.md` became `index.md`, `quickstart.md` moved to the docs root, and a generated `requirements.md` states only what the resolver enforces. `docs/superpowers/` and its subfolders gained the `_index.md` landings and frontmatter they were missing, which had been downgrading the docs site's grading from `complete` to `partial`. All relative links repaired and verified
- **Pest constraint widened to `^4.1 || ^5.0`** — Pest 5 is stable; the package was a major behind
- **Tests now exercise the real MCP protocol** — Every test drove tools through `execute()` directly, so JSON-RPC argument delivery, response serialization, the `isError` flag, and outputSchema conformance had no coverage at all; `StatamicMcpServerTest` even read protected properties by reflection. A new `McpProtocolSurfaceTest` drives tools through `StatamicMcpServer::tool()`. This required registering `Laravel\Mcp\Server\McpServiceProvider` in `TestCase::getPackageProviders()` — Testbench does not run package auto-discovery, so without it `Laravel\Mcp\Request` never receives arguments and protocol-level tests pass while asserting nothing
- **`composer stan` passes `--memory-limit=2G`** — PHPStan crashed its parallel worker at PHP's default 128M; 1G proved marginal once `tests/Fixtures` joined the analysis paths
- **Removed the `composer test:parallel` script** — It could not work for the reason above; use `composer test`

## [2.8.0] - 2026-07-29

### Changed
- **laravel/mcp ^0.9 support** — Widens the dependency constraint to `^0.6 || ^0.7 || ^0.8 || ^0.9`. The 0.9 breaking changes are client-side only (the `Laravel\Mcp\Client\Contracts\Transport` contract gained `setProtocolVersion()`, and the MCP *client* now only negotiates `2025-11-25`/`2025-06-18`); this addon ships a server and does not implement either, so no code changes were needed. Server-side gains come for free: stricter JSON-RPC `params`/`arguments` validation (`-32602` on non-object payloads), a `CursorPaginator` fix for negative cursor offsets, and hardened OAuth dynamic client registration

## [2.7.0] - 2026-07-05

### Changed
- **laravel/mcp ^0.8 support** — Widens the dependency constraint to `^0.6 || ^0.7 || ^0.8`, allowing the latest laravel/mcp release (v0.8.x) with MCP client support, MCP UI Apps, `ResourceLink` content type, and OAuth improvements. All patterns used by this addon are unchanged across 0.6–0.8

### Fixed
- **Blueprint `types` action null handles** — Blueprints without a handle are now skipped during type analysis instead of triggering a type error
- **Output buffer cleanup type safety** — Shutdown output-buffer sweep in the MCP server no longer relies on an impossible `false` comparison flagged by stricter dependency types

## [2.6.1] - 2026-06-30

### Fixed
- **Confirmation token retry loop** — Router schemas now expose `confirmation_token` as an optional top-level argument, giving MCP clients a valid schema slot for the token returned by confirmation-required responses (#34)
- **Confirmation payload drift on retry** — Confirmation tokens now preserve the originally confirmed arguments, tolerate associative key reordering, and restore the confirmed payload before executing the gated action while still rejecting changed payloads and reordered lists (#34)

### Tests
- Added regression coverage for schema exposure, two-step confirmation retries, nested associative argument reordering, and confirmed payload restoration

## [2.6.0] - 2026-06-02

### Fixed
- **Eloquent user ID compatibility** — Normalizes Statamic user IDs before MCP token ownership checks so sites using the Eloquent users driver no longer hit strict type errors in the MCP dashboard or token flows (#31)
- **OAuth authorization user IDs** — Applies the same user ID normalization when creating OAuth authorization codes for Eloquent-backed users
- **Fresh install dependency compatibility** — Allows `laravel/mcp` `^0.7` alongside `^0.6` and updates installation docs/generated guidance to match (#31)

### Tests
- Added regression coverage for integer Eloquent user IDs, string user IDs, and missing current users in MCP dashboard user resolution

## [2.5.0] - 2026-05-06

### Added
- **Revision-aware entry workflows** — When a collection has revisions enabled, the MCP server now respects Statamic's editorial workflow instead of bypassing it with direct saves. Updates to published entries create a working copy (published content unchanged), creates use `store()` for draft + initial revision, and publish/unpublish delegate to Statamic's built-in revision-aware methods (#30)
- **New entry actions**: `list_revisions`, `get_revision`, `restore_revision`, `publish_working_copy` — full revision lifecycle management via the `statamic-entries` router
- **`version` parameter on entry get** — Retrieve `published`, `working_copy`, or `latest` version of an entry
- **`HandlesRevisions` trait** — Encapsulates revision-aware save, list, get, and restore operations matching the Statamic CP's exact editorial workflow
- **Confirmation gate defaults for revision actions** — `restore_revision` and `publish_working_copy` now require confirmation in production by default
- **31 new tests** covering the full revision lifecycle (create → working copy → list revisions → restore → publish)

### Fixed
- **Multi-site response in `publishWorkingCopyAction`** — Re-fetches entry with site context to return correct localized data
- **Stale revision metadata after restore** — `revision_status` and `restored_as_working_copy` now reflect actual state after `restoreRevisionAction`
- **`normalizeTableCell` unbounded recursion** — Table cell normalization now unwraps one level only, preventing stack overflow on deeply nested structures
- **`filterOutputFields` denied field stripping** — Denied fields are now correctly stripped from list responses and nested entry data, not just top-level get responses
- **`generateBlueprint` field array shape** — Blueprint generation now produces the correct indexed handle/field format
- **`revision_message` type safety** — `publishWorkingCopyAction` validates that revision message is a string before passing to Statamic
- **PHPStan L8 compliance** — Resolved mixed offset access in `filterOutputFields` recursive field filtering

## [2.4.0] - 2026-05-05

### Added
- **Per-field wire-format spec** — `BlueprintsRouter::get` now emits a `_format_spec` per field describing the exact wire format (shape, allowed node types, set handles, canonical examples). Covers bard (inline + full), replicator, grid, group, markdown, scalar, select/checkbox, relationship, asset, table, and date fields. Controllable via `include_format_spec` (default `true`) and `max_format_depth` (default 2, max 5) parameters (#29)
- **`FieldFormatException`** — new exception class for malformed bard/replicator/grid/table input, with precise field-path error messages that survive production sanitization
- **Client-safe exception allow-list** — `FieldFormatException`, `ValidationException`, `FieldtypeNotFoundException`, and `BlueprintNotFoundException` messages now reach the client in production instead of being replaced with a generic placeholder
- **Configurable confirmation actions** — new `confirmation.actions` config block allows per-domain control over which actions require confirmation tokens. Domains not listed fall back to `default`. `*` gates every action; `[]` disables the gate. Shipped defaults preserve existing behaviour (#26)
- **`ConfirmationActionGate`** — new helper that resolves `(domain, action)` → gated? from config, replacing the previous hardcoded logic in `RequiresConfirmation`

### Fixed
- **Entry slug self-collision on update** — `updateEntry()` no longer fails with "slug already taken" when updating an entry without changing its slug. The fix passes the current entry ID as exclusion to `UniqueEntryValue`, matching the pattern used in `createEntry()` (#28)
- **Table cell normalization** — `SanitizesFieldData` now correctly normalizes `{value: …}` objects in table cells to plain strings, preventing `[object Object]` rendering in the CP
- **Playwright login throttling in CI** — Browser tests now use a shared `globalSetup` + `storageState` pattern (single login per run) instead of per-test login, preventing Statamic's login throttle from failing the last test

## [2.2.4] - 2026-04-14

### Fixed
- **Critical:** Update action on entries, terms, and globals no longer crashes with `Cannot access offset of type string on string` when blueprints include third-party fieldtypes (e.g., SEO Pro) — validation now falls back to incoming-only fields when a `TypeError` occurs in the full-merge validation pipeline
- **OAuth CIMD discovery:** `cimd_enabled` config checks now use `(bool)` cast with `true` default — strict `=== true` comparison against env strings silently disabled CIMD, and published configs missing the key (due to `mergeConfigFrom` shallow merge) returned `null`
- **OAuth path-suffixed discovery:** Added `/.well-known/oauth-authorization-server/{path}` and `/.well-known/oauth-protected-resource/{path}` routes per RFC 8414 §3.1 — MCP clients discovering metadata for a server at e.g. `/mcp/statamic` use path insertion and previously got 403

### Added
- **OAuth 2.1 CIMD support:** Client ID Metadata Document (CIMD) resolution for OAuth authorization — MCP clients can now present verified application identity (name, logo, policy URLs) on the consent screen
- CIMD resolver with SSRF protection, JSON-LD validation, and configurable caching
- Consent screen shows verified client metadata when available (name, logo, redirect URIs)
- `/.well-known/oauth-authorization-server` now advertises `client_id_metadata_document_supported`
- 10 new update validation tests covering deep nested replicator/bard/grid/group blueprints, round-trip create→update, and simulated crashing third-party fieldtypes
- 24 discovery endpoint tests covering CIMD config edge cases, path-suffixed discovery, CP route changes, revocation endpoint, and full client discovery flow simulation (protected-resource → path-suffixed AS metadata → CIMD check)
- Comprehensive CIMD test suite (unit, feature, E2E)

## [2.1.0] - 2026-04-13

### Fixed
- **Critical:** Entry updates with `terms` field type no longer crash with "Cannot access offset of type string on string" (ENG-697)
- **Critical:** Data saved via MCP now matches CP format — all routers (entries, terms, globals) call `$fields->process()->values()` after validation, running fieldtype transformations (Terms prefix stripping, Bard node normalization, Relationship wrapping)
- **Critical:** OAuth auth code and refresh token double-spend prevented — added `fflush()` before lock release in `BuiltInOAuthDriver`
- **Security:** OAuth `client_name` is now sanitized with `strip_tags()` on registration to prevent stored XSS from unauthenticated clients
- **Security:** OAuth mutation endpoints (`/mcp/oauth/token`, `/register`, `/revoke`) now enforce HTTPS in production via `EnsureSecureTransport` middleware
- **Security:** Removed inconsistent hardcoded scope fallbacks in `AuthorizeController` — config file is now the single source of truth
- Collection `taxonomies` field now persists on create and update (previously silently ignored)
- Taxonomy `preview_targets` and `default_status` now persist on create and update
- Navigation `collections` now persists on create and update
- Removed broken `$taxonomy->collections()` setter call from `configureTaxonomy()` — the association is stored on the collection side
- File handle leak in `BlueprintsRouter` when `flock()` fails after `fopen()` succeeds
- Missing `fflush()` before lock release in `FileTokenStore::updateIndex()` and `removeFromIndex()`
- Relationship fields (`terms`, `entries`, `users`, `assets`) and `checkboxes` now normalize bare strings to arrays before validation

### Changed
- **OAuth default scopes** changed from `*` (all permissions) to read-only: `content:read`, `blueprints:read`, `structures:read`, `entries:read`, `terms:read`, `globals:read`, `assets:read`, `system:read`, `content-facade:read`. Override via `STATAMIC_MCP_OAUTH_DEFAULT_SCOPES` env var.
- `sanitizeStoredFieldDataForValidation()` marked as `@deprecated` — exists only for backward compatibility with content saved by MCP prior to v2.1 without the `process()` step. Safe to remove once all MCP-created content has been re-saved.

### Added
- `SanitizesFieldData` trait for pre-validation input normalization across all content routers
- 8 new integration tests for terms field updates (ENG-697 reproduction)
- 6 new structure router tests for taxonomy and navigation field persistence

## [2.0.4] - 2026-04-10

### Fixed
- **Critical:** Entry creation no longer crashes with "Cannot access offset of type string on string" when data contains complex nested fields (Bard, Replicator)
- Date fields now accept any common format (Y-m-d, Y-m-d H:i, ISO 8601, `{date, time}` objects) — values are normalized to the Zulu format Statamic expects before validation
- `date` and `published` in entry data are now correctly extracted as first-class entry properties instead of failing blueprint validation on dated collections

### Added
- `NormalizesDateFields` trait for consistent date handling across all routers (Entries, Terms, Globals)
- 13 new integration tests covering date normalization, published extraction, and error handling

## [2.0.3] - 2026-04-09

### Fixed
- **Critical:** Blueprint update action no longer destroys existing fields — fields are now merged by default instead of replaced
- Blueprint update preserves tab and section organization in multi-tab blueprints

### Added
- `replace_fields` parameter on blueprint update for explicit full-replacement when needed

## [2.0.2] - 2026-03-19

### Fixed
- Install command no longer crashes on sites without a database — migrations are now skipped automatically when file-based storage drivers are configured (the default)
- Config publish prompt: confirming "Overwrite? yes" now actually overwrites the file (previously `--force` stayed false, so `vendor:publish` silently skipped it)
- Migration failures are caught with actionable guidance instead of crashing the installer
- Completion message now reflects what actually happened during install

### Added
- `--skip-migrations` flag on `mcp:statamic:install` as an explicit escape hatch

## [2.0.1] - 2026-03-18

### Fixed
- Token expiry date validation no longer blocks submission — `max_token_lifetime_days` is now a default suggestion, not a hard server-side rejection
- Token form error feedback uses Statamic toast notifications and native `ui-error-message` components with red border highlighting

### Added
- Scope presets (Read Only, Content Editor, Full Access) in token create/edit form, matching documented common combinations
- Preset-aware badge display in admin token table — shows preset name instead of listing individual scopes
- Admin token form now uses Statamic-style grouped permission cards with per-group "Check All"

### Removed
- Internal development plans and specs (`docs/superpowers/`) accidentally included in v2.0.0

## [2.0.0] - 2026-03-18

### Breaking Changes

#### Statamic v5 Support Removed
- **Statamic v6.6+ only** — minimum requirement is now `statamic/cms:^6.6`
- **Laravel 12+ only** — supports `laravel/framework:^12.0` and `^13.0` (via Statamic v6)
- Removed `StatamicVersion` dual-version detection helper
- Removed v5 compatibility shims and feature flags

#### Laravel MCP Upgraded to v0.6
- **laravel/mcp** upgraded from `^0.2` to `^0.6`
- Tools now use `#[Name]` and `#[Description]` attributes instead of methods
- `BaseStatamicTool` now wraps `handle()` — tools implement `executeInternal()`
- Response format changed to `Response::structured()` / `Response::error()`
- `ToolResult` and `ToolInputSchema` classes removed

#### Architecture: Single-Purpose Tools → Router Pattern
- **11 MCP tools** replace 140+ individual tools
- Each router handles multiple actions via `action` parameter
- Tool names changed from dot notation (`statamic.blueprints.list`) to hyphenated routers (`statamic-blueprints` with `action: list`)
- `type` parameter renamed to `resource_type` (avoids JSON Schema keyword collision)

#### Config Restructured
- Per-tool config (`tools.statamic.content.web_enabled`) replaced with simple toggles (`tools.entries.enabled`)
- Per-tool rate limiting removed — single global `rate_limit.max_attempts`
- New sections: `stores`, `storage`, `oauth`, `security`, `dashboard`
- Re-publish required: `php artisan vendor:publish --tag=statamic-mcp-config --force`

### Added

#### Storage Driver Abstraction
- `TokenStore` and `AuditStore` contracts with config-based class binding
- File drivers (default): YAML tokens with hash index, JSONL audit with SplFileObject
- Database drivers: Eloquent with atomic operations
- `mcp:migrate-store` command for file↔database migration
- `mcp:prune-audit` and `mcp:prune-tokens` commands

#### OAuth 2.1 Authorization Server
- Discovery endpoints (`.well-known/oauth-protected-resource`, `.well-known/oauth-authorization-server`)
- Dynamic Client Registration (RFC 7591) with per-IP quotas
- Authorization Code + PKCE S256 with Blade consent screen
- Refresh token rotation (30-day TTL)
- Token revocation endpoint (RFC 7009)
- `OAuthDriver` interface with built-in file driver and database driver
- Live-tested with ChatGPT and Claude Desktop

#### Scoped API Token System
- 21 granular permission scopes via `TokenScope` backed string enum
- SHA-256 hashed token storage
- Custom `McpTokenGuard` registered as `mcp` auth guard
- `AuthenticateForMcp` middleware with Bearer + Basic Auth fallback
- `RequireMcpPermission` middleware for scope validation

#### CP Dashboard (Vue 3 + KITT UI)
- User page (`/cp/mcp`): Connect + My Tokens
- Admin page (`/cp/mcp/admin`): All Tokens, Activity log, System info
- Stateful tab URLs (`?tab=activity`)
- Connect panel with client config snippets for Claude/Cursor/ChatGPT/Windsurf

#### Domain Routers
- `statamic-blueprints` — list, get, create, update, delete, scan, generate, types, validate
- `statamic-entries` — list, get, create, update, delete, publish, unpublish
- `statamic-terms` — list, get, create, update, delete
- `statamic-globals` — list, get, update
- `statamic-structures` — list, get, create, update, delete, configure (collections, taxonomies, navigations, sites, global sets)
- `statamic-assets` — list, get, create, update, delete, move, copy, upload (containers + assets)
- `statamic-users` — list, get, search, create, update, delete, activate, deactivate, assign_role, remove_role
- `statamic-system` — info, health, cache_status, cache_clear, cache_warm, config_get, config_set
- `statamic-content-facade` — content_audit, cross_reference

#### Agent Education Tools
- `statamic-system-discover` — intent-based tool and action discovery
- `statamic-system-schema` — tool schema inspection

#### Security Hardening
- Centralized web context security guard in `BaseRouter`
- Path traversal protection on all file-based stores
- Recursive null-byte validation on tool arguments
- Atomic rate limiting per-IP and per-token
- OAuth scope injection prevention
- PKCE S256 strict enforcement with timing-safe comparison
- Constant-time Basic Auth to prevent user enumeration
- PII redaction in audit logs
- `config_set` restricted to CLI-only
- CORS wildcard rejected in production
- Directory permissions 0700 for token/OAuth storage
- Correlation ID validation (alphanumeric, max 128 chars)
- HTTPS error messages don't leak env variable names
- Per-IP OAuth client registration quota (default 5)

#### Audit Logging
- Single entry per tool call with user/token/IP context
- Mutation tracking (resource type, id, changed fields)
- Pluggable storage backends (file JSONL or database)
- Admin dashboard with filters and detail panel

#### Events
- `McpTokenSaved` and `McpTokenDeleted` for Statamic Git automation

#### Documentation
- Complete docs site: introduction, getting-started, authentication, configuration, tools
- UPGRADE.md migration guide from v1.x

### Changed
- `web.enabled` now defaults to `true` (was `false`)
- `symfony/yaml` constraint widened to `^7.0 || ^8.0`
- All error responses use standardized envelope format
- `parseBytes()` replaced with PHP 8.3 `ini_parse_quantity()`
- `DatabaseTokenStore` uses `fill()` instead of `forceFill()`

### Removed
- Statamic v5 compatibility layer and `StatamicVersion` helper
- 140+ individual single-purpose tool classes (replaced by 11 routers)
- Dot-notation tool names
- `getToolName()` / `getToolDescription()` method pattern
- `ToolResult` and `ToolInputSchema` imports
- `AuditService` (consolidated into `ToolLogger`)
- `McpRateLimiter` (dead code)
- `statamic-content` router (split into entries, terms, globals)
- `HandlesContainers` trait (dead code — shadowed by AssetsRouter)
- `decay_minutes` config key (was unused)
- `web.middleware` config key (never existed)
- Deprecated `ToolLogger` no-op methods
- Custom OAuth login page

---

## [1.4.0] - 2025-01-19

### Added

#### Statamic v6 Dual Version Support
- Full compatibility with both Statamic v5.65+ and v6.0+
- Automatic version detection — no code changes needed when upgrading
- Zero breaking changes — all tools work identically across versions
- Asset permission compatibility for both v5 and v6 models

#### Version Detection System
- `StatamicVersion` helper class for runtime version detection
- Methods: `isV6OrLater()`, `supportsV6OptIns()`, `hasV6AssetPermissions()`

#### Testing Infrastructure
- GitHub Actions test matrix for PHP 8.3 × Statamic 5.65/6.0
- Automated dual-version validation in CI/CD

### Changed
- **PHP**: Minimum version raised to `^8.3`
- **Statamic CMS**: Updated to `^5.65|^6.0`
- **Laravel**: Support for `^11.0|^12.0`
- **Pest**: Updated to `^4.1`
- PHPStan Level 8 compliance across all files
- Composer minimum-stability changed from `dev` to `stable`

---

## [1.3.0] - 2025-01-15

### Added
- Blueprint type analysis and generation tools
- Comprehensive global management (sets and values)
- Advanced template performance analysis
- Navigation structure management
- Enhanced cache management with selective clearing

---

## [1.2.0] - 2025-01-10

### Added
- Entry management tools (CRUD operations)
- Term management tools (taxonomy terms)
- Template validation and linting
- Development workflow tools

---

## [1.1.0] - 2025-01-05

### Added
- Collection and taxonomy management
- Blueprint scanning and validation
- System information and cache tools

---

## [1.0.0] - 2025-01-01

### Added
- Initial release
- Core MCP server functionality
- Basic blueprint and content management
- Laravel MCP v0.2.0 integration
- Comprehensive test suite

[2.10.1]: https://github.com/cboxdk/statamic-mcp/compare/v2.10.0...v2.10.1
[2.10.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.9.1...v2.10.0
[2.9.1]: https://github.com/cboxdk/statamic-mcp/compare/v2.9.0...v2.9.1
[2.9.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.8.0...v2.9.0
[2.8.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.7.0...v2.8.0
[2.7.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.6.1...v2.7.0
[2.6.1]: https://github.com/cboxdk/statamic-mcp/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.5.0...v2.6.0
[2.5.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.3.0...v2.4.0
[2.0.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.4.0...v2.0.0
[1.4.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/cboxdk/statamic-mcp/releases/tag/v1.0.0
