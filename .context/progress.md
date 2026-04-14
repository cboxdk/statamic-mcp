## Codebase Patterns

- **Value objects** in `src/OAuth/` use `private` constructor + static `tryFrom()` factory returning `?self`
- **Config** uses `env()` helpers with typed casts: `(int) env(...)` for integers, plain `env()` for booleans
- **Config env naming**: `STATAMIC_MCP_OAUTH_{FEATURE}_{SETTING}` pattern
- **Test files** use `declare(strict_types=1)` and pure Pest syntax (no `uses()` needed for unit tests)
- **PHPStan**: Level 9, needs `--memory-limit=512M` to avoid OOM on this machine
- **Directory structure**: Domain subdirectories under `src/OAuth/` (e.g., `Cimd/`, `Concerns/`, `Drivers/`)
- **All PHP files**: Must have `declare(strict_types=1)`
- **Exceptions**: Use `final class` + `readonly string $errorCode` property + extend `\RuntimeException`
- **Validation value objects**: Use `fromArray()` static factory that throws on failure (vs `tryFrom()` returning null for simple parsing)
- **Trait reuse**: `ValidatesRedirectUris` trait can be used in any class needing redirect URI validation
- **Service singletons**: Register with `$this->app->singleton(ClassName::class)` in `ServiceProvider::register()` — no closure needed for simple classes
- **Http testing**: `Http::fake()` with URL patterns works well; disable SSRF (`cimd_block_private_ips=false`) in non-SSRF tests since `gethostbyname()` can't be faked
- **SSRF testing**: Use `localhost` (resolves to 127.0.0.1) and `.invalid` TLD (guaranteed unresolvable per RFC 2606) for DNS failure tests
- **CimdClientId test URLs**: Must use a meaningful path (not just `/`) — use `https://example.com/oauth/metadata.json` pattern, not `https://example.com/`
- **Discovery controller config checks**: Use `=== true` for strict boolean comparison with `config()` values
- **BuiltInOAuthDriver.findClient()**: Throws `OAuthException` for URL-format client_ids (fails `sanitizeFilename`) — wrap in try-catch when CIMD fallback is needed
- **abort() in tests**: `abort(400, 'message')` renders the error page template, not the raw message — use `assertStatus(400)` only, not `assertSee('message')`
- **Token controller CIMD pattern**: Use union return type `OAuthClient|JsonResponse|null` for methods that may return an error response or a resolved client
- **E2E OAuth testing**: Approve via POST to authorize endpoint, extract code from redirect Location header with `parse_url()`/`parse_str()`, then POST to token endpoint
- **Http::fake() re-faking**: Call `Http::fake()` again mid-test to change behavior (e.g., first successful, then connection error) — works because it replaces the fake handler

## US-001: CIMD config and client ID URL validator
- Added 5 CIMD config options under `oauth` key in `config/statamic/mcp.php` with env var support
- Created `src/OAuth/Cimd/CimdClientId.php` — value object with `tryFrom()`, `toString()`, `getHost()`
- Created `tests/Unit/OAuth/Cimd/CimdClientIdTest.php` — 18 Pest tests covering all acceptance criteria
- **Files changed**:
  - `config/statamic/mcp.php` — added CIMD config block
  - `src/OAuth/Cimd/CimdClientId.php` — new file
  - `tests/Unit/OAuth/Cimd/CimdClientIdTest.php` — new file
- **Learnings for future iterations:**
  - Value object pattern: private constructor + static factory returning nullable self
  - `parse_url()` returns `false` on malformed URLs but also returns partial arrays — always check specific keys with `isset()`
  - PHPStan level 9 is strict about nullable types — use `?self` return type and null-safe operator `?->` in tests
  - Config values follow existing naming convention: `cimd_` prefix within the `oauth` array
  - Test directory mirrors source directory: `tests/Unit/OAuth/Cimd/` matches `src/OAuth/Cimd/`

## US-002: CIMD metadata document parsing and validation
- Created `src/OAuth/Cimd/CimdMetadata.php` — immutable value object that parses/validates CIMD JSON documents
- Created `src/OAuth/Cimd/CimdValidationException.php` — exception with `errorCode` string property
- Created `tests/Unit/OAuth/Cimd/CimdMetadataTest.php` — 31 Pest tests covering all acceptance criteria
- **Files changed**:
  - `src/OAuth/Cimd/CimdMetadata.php` — new file
  - `src/OAuth/Cimd/CimdValidationException.php` — new file
  - `tests/Unit/OAuth/Cimd/CimdMetadataTest.php` — new file
- **Learnings for future iterations:**
  - `fromArray()` is the right factory pattern when validation can fail with detailed errors (vs `tryFrom()` for simple null/success)
  - Pint auto-fixes: `not_operator_with_successor_space`, `single_line_empty_body`, `braces_position` — run `pint` before committing
  - `ValidatesRedirectUris` trait provides `validateRedirectUri(string): bool` — works in any class, not just controllers
  - PHPStan level 9 handles `list<string>` type annotations well for array properties
  - Helper functions at top of test file (e.g., `validClientId()`, `validMetadataArray()`) keep tests DRY and readable

## US-003: CIMD resolver service with SSRF protection
- Created `src/OAuth/Cimd/CimdResolver.php` — service class that fetches/validates CIMD metadata with SSRF protection, size limits, caching, and no-redirect policy
- Created `src/OAuth/Cimd/CimdFetchException.php` — exception for network/fetch errors with `errorCode` property
- Registered `CimdResolver` as singleton in `ServiceProvider::register()`
- Created `tests/Unit/OAuth/Cimd/CimdResolverTest.php` — 22 Pest tests covering all acceptance criteria
- **Files changed**:
  - `src/OAuth/Cimd/CimdResolver.php` — new file
  - `src/OAuth/Cimd/CimdFetchException.php` — new file
  - `src/ServiceProvider.php` — added CimdResolver singleton registration
  - `tests/Unit/OAuth/Cimd/CimdResolverTest.php` — new file
  - `.context/progress.md` — updated
- **Learnings for future iterations:**
  - `gethostbyname()` returns the hostname string unchanged when DNS resolution fails — use this as the failure signal
  - `Http::fake()` callback closures with `never` return type work for simulating connection exceptions
  - `ip2long()` returns `false` for non-IPv4 addresses — handle gracefully
  - Private IP ranges: 10.x, 172.16-31.x, 192.168.x, 127.x, 169.254.x, 0.x — plus IPv6 `::1` and `fc00::/7`
  - `Cache::remember()` not ideal here since we must NOT cache exceptions — use explicit `get()`/`put()` with early return instead
  - Pint auto-fixes `new ClassName()` to `new ClassName` (no parens) and sorts imports — run before committing
  - `maxRedirects(0)` on Laravel HTTP client returns the redirect response directly as a non-successful response

## US-004: OAuthClient CIMD fields and discovery endpoint
- Extended `src/OAuth/OAuthClient.php` with three new optional constructor parameters (`clientUri`, `logoUri`, `isCimd`) and a `fromCimdMetadata()` static factory
- Updated `src/Http/Controllers/OAuth/DiscoveryController.php` to include `client_id_metadata_document_supported: true` in authorization server metadata when `cimd_enabled` config is true
- Added 2 new feature tests for CIMD discovery field (enabled/disabled) in existing `DiscoveryEndpointTest`
- Added 5 new unit tests for OAuthClient CIMD properties and factory in existing `OAuthClientTest`
- **Files changed**:
  - `src/OAuth/OAuthClient.php` — added `clientUri`, `logoUri`, `isCimd` properties + `fromCimdMetadata()` factory
  - `src/Http/Controllers/OAuth/DiscoveryController.php` — conditional `client_id_metadata_document_supported` in auth server metadata
  - `tests/Feature/OAuth/DiscoveryEndpointTest.php` — 2 new tests for CIMD field presence/absence
  - `tests/Unit/OAuth/OAuthClientTest.php` — 5 new tests for CIMD properties and factory
  - `.context/progress.md` — updated
- **Learnings for future iterations:**
  - `CimdClientId::tryFrom()` rejects URLs with just `/` as path — use `https://example.com/oauth/metadata.json` in tests
  - OAuthClient uses public constructor (not private + factory) since it's a data class, not a validation value object — new optional params with defaults maintain backward compatibility
  - `config()` returns mixed — use `=== true` for strict boolean checks in conditional logic
  - Existing driver code (`BuiltInOAuthDriver`, `DatabaseOAuthDriver`) uses named params so new trailing optional params don't break anything

## US-005: Authorization flow CIMD integration
- Modified `AuthorizeController::show()` and `approve()` to fall back to CIMD resolution when `findClient()` returns null or throws
- Added `CimdResolver` as a constructor dependency in `AuthorizeController`
- Added private `resolveClientViaCimd()` method that handles CIMD URL validation, config check, resolver call, and exception handling
- Wrapped `findClient()` calls in try-catch for `OAuthException` since `BuiltInOAuthDriver` throws on URL-format client_ids
- Updated consent view (`resources/views/oauth/consent.blade.php`) with CIMD-specific display: hostname subtitle, logo image, localhost redirect URI warning
- Created `tests/Feature/OAuth/CimdAuthorizeTest.php` — 13 tests covering all acceptance criteria
- **Files changed**:
  - `src/Http/Controllers/OAuth/AuthorizeController.php` — CIMD fallback in show() and approve(), new resolveClientViaCimd() method
  - `resources/views/oauth/consent.blade.php` — CIMD client display (hostname, logo, localhost warning)
  - `tests/Feature/OAuth/CimdAuthorizeTest.php` — new file with 13 feature tests
  - `.context/progress.md` — updated
- **Learnings for future iterations:**
  - `BuiltInOAuthDriver::findClient()` throws `OAuthException` when the client_id can't be a filename (URLs with slashes) — must wrap in try-catch for CIMD fallback to work
  - `abort(400, 'message')` in Statamic test environment renders the error page template, not the raw message text — `assertSee()` on abort messages doesn't work; use `assertStatus()` only
  - Blade's `parse_url()` can be used directly in templates: `{{ parse_url($client->clientId, PHP_URL_HOST) }}`
  - `collect()->every()` is a clean way to check if all redirect URIs are localhost in Blade templates
  - The `OAuthException` import was needed alongside the CIMD exception imports in the controller

## US-006: Token endpoint CIMD integration and E2E flow
- Modified `OAuthTokenController::issueTokenResponse()` to fall back to CIMD resolution when `findClient()` returns null or throws
- Added `CimdResolver` as a constructor dependency in `OAuthTokenController`
- Added private `resolveClientViaCimd()` method returning `OAuthClient|JsonResponse|null` — returns JSON error for CIMD failures instead of `abort()` (API endpoint returns JSON, not HTML)
- Wrapped `findClient()` in try-catch for `OAuthException` (same pattern as AuthorizeController)
- Verified `exchangeCode()` and `exchangeRefreshToken()` work with URL-format client_ids — they use string comparison on stored `client_id`, no filename sanitization
- Created `tests/Feature/OAuth/CimdFlowTest.php` — 10 E2E tests covering all acceptance criteria
- **Files changed**:
  - `src/Http/Controllers/OAuth/OAuthTokenController.php` — CIMD fallback in issueTokenResponse(), new resolveClientViaCimd() method, CimdResolver dependency
  - `tests/Feature/OAuth/CimdFlowTest.php` — new file with 10 E2E feature tests
  - `.context/progress.md` — updated
- **Learnings for future iterations:**
  - Token controller uses `JsonResponse` for errors (not `abort()`) since it's an API endpoint — return union type `OAuthClient|JsonResponse|null`
  - `exchangeCode()` and `exchangeRefreshToken()` use hash-based filenames for code/refresh storage but string comparison for `client_id` — URL-format client_ids work without driver changes
  - `Http::fake()` can be called multiple times in a single test to change behavior mid-flow (e.g., first successful, then connection error)
  - E2E OAuth testing pattern: POST to approve → extract code from redirect Location → POST to /mcp/oauth/token → verify token properties via TokenService
  - Auth code is consumed by `exchangeCode()` before `issueTokenResponse()` runs — if CIMD resolution fails at token issuance, the code is already used (acceptable trade-off since CIMD should be reachable if it was during authorization)
