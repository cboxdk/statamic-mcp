# Remaining Features — Design Spec

## Features to implement

### Feature 6: OAuth Refresh Tokens

Add `refresh_token` support to the OAuth flow per OAuth 2.1 spec.

**Changes:**
- Token endpoint returns `refresh_token` alongside `access_token`
- New endpoint: `POST /mcp/oauth/token` with `grant_type=refresh_token`
- Refresh tokens stored alongside auth codes in `OAuthDriver` (file or database)
- Refresh token rotation: each use generates a new refresh+access pair
- Configurable TTL: `oauth.refresh_token_ttl` (default 30 days)
- `OAuthDriver` interface gains: `createRefreshToken()`, `exchangeRefreshToken()`

**Security:**
- Refresh tokens are single-use (rotation prevents replay)
- Stored hashed like auth codes
- Bound to client_id (can't be used by different client)

### Feature 7: Database OAuth Driver

Implement `DatabaseOAuthDriver` using Eloquent models for clients and codes.

**Changes:**
- Create `src/OAuth/Drivers/DatabaseOAuthDriver.php` implementing `OAuthDriver`
- Uses existing migrations at `database/migrations/oauth/`
- Create `src/OAuth/Models/OAuthClientModel.php` Eloquent model
- Create `src/OAuth/Models/OAuthCodeModel.php` Eloquent model
- Atomic code exchange via `UPDATE ... WHERE used = false` (row-level lock)
- Config: `'oauth.driver' => DatabaseOAuthDriver::class`

### Feature 8: Token Revocation Endpoint

Implement RFC 7009 token revocation.

**Changes:**
- New endpoint: `POST /mcp/oauth/revoke`
- Accepts `token` parameter (the access_token to revoke)
- Calls `TokenService::revokeToken()` after finding token by hash
- Returns 200 OK regardless of whether token existed (per RFC 7009)
- Add route to `routes/oauth.php`

### Feature 9: FileTokenStore Concurrency Improvement

Improve locking strategy for the file-based token store.

**Changes:**
- Use atomic rename for index updates instead of flock read-modify-write:
  1. Write new index to `{storagePath}/.index.tmp`
  2. `rename('.index.tmp', '.index')` — atomic on local filesystems
- Add `flock()` around `writeYamlFile()` for individual token writes
- Document NFS limitation prominently

### Feature 10: Playwright E2E Tests

Browser-based tests for the CP dashboard.

**Changes:**
- Install Playwright via npm: `@playwright/test`
- Create `tests/Browser/` directory
- Test: Connect tab shows endpoint URL and client buttons
- Test: Token create/edit/delete flow in UI
- Test: Admin activity tab shows entries, filters work, detail panel opens
- Test: OAuth consent screen renders and submits correctly
- CI: Add Playwright job with `npx playwright install chromium`
- Separate CI job (not blocking main test matrix)
