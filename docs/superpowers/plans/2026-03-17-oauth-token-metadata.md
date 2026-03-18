# OAuth Token Metadata Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add OAuth client metadata to tokens so the dashboard shows integration source and hides regenerate for OAuth tokens.

**Architecture:** Add nullable `oauth_client_id` and `oauth_client_name` fields to `McpTokenData`. Propagate through TokenStore contract, both store implementations (DB + File), TokenService, and OAuthTokenController. Update Vue dashboard to show an "OAuth" badge with client name and hide the regenerate button for OAuth tokens. Scope editing stays available for all tokens.

**Tech Stack:** PHP 8.3, Laravel 12, Statamic v6, Vue 3, Pest

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `src/Storage/Tokens/McpTokenData.php` | Modify | Add `?string $oauthClientId`, `?string $oauthClientName` |
| `src/Contracts/TokenStore.php` | Modify | Add optional OAuth params to `create()` |
| `src/Storage/Tokens/DatabaseTokenStore.php` | Modify | Persist/read OAuth fields |
| `src/Storage/Tokens/FileTokenStore.php` | Modify | Persist/read OAuth fields in YAML |
| `src/Auth/McpToken.php` | Modify | Add `oauth_client_id`, `oauth_client_name` to fillable |
| `database/migrations/tokens/add_oauth_metadata_to_mcp_tokens_table.php` | Create | Add nullable columns |
| `src/Auth/TokenService.php` | Modify | Accept optional OAuth params in `createToken()` |
| `src/Http/Controllers/OAuth/OAuthTokenController.php` | Modify | Pass clientId/clientName to createToken() |
| `src/Http/Controllers/CP/DashboardController.php` | Modify | Include OAuth fields in `serializeToken()` |
| `src/Http/Controllers/CP/TokenController.php` | Modify | Block regenerate for OAuth tokens |
| `resources/js/pages/McpPage.vue` | Modify | OAuth badge, hide regenerate |
| `resources/js/pages/McpAdminPage.vue` | Modify | OAuth badge, hide regenerate |

---

### Task 1: Extend McpTokenData DTO

- [ ] Add `?string $oauthClientId = null` and `?string $oauthClientName = null` to constructor
- [ ] Run PHPStan to verify no breaks

### Task 2: Database migration

- [ ] Create `add_oauth_metadata_to_mcp_tokens_table.php` migration
- [ ] Add nullable `oauth_client_id` and `oauth_client_name` string columns

### Task 3: Update McpToken Eloquent model

- [ ] Add `oauth_client_id` and `oauth_client_name` to `$fillable`
- [ ] Add PHPDoc properties

### Task 4: Update TokenStore contract + both implementations

- [ ] Add `?string $oauthClientId = null, ?string $oauthClientName = null` to `TokenStore::create()`
- [ ] Update `DatabaseTokenStore::create()` — persist fields, update `toData()` to read them
- [ ] Update `FileTokenStore::create()` — persist in YAML, update `toData()` to read them
- [ ] Update `import()` in both stores to handle OAuth fields

### Task 5: Update TokenService

- [ ] Add `?string $oauthClientId = null, ?string $oauthClientName = null` params to `createToken()`
- [ ] Pass through to store
- [ ] Update `validateToken()` to include OAuth fields in the fresh copy

### Task 6: Update OAuthTokenController

- [ ] Pass `$clientId` and `$clientName` to `createToken()` in both grant handlers

### Task 7: Update DashboardController + TokenController

- [ ] Add `oauth_client_id` and `oauth_client_name` to `serializeToken()`
- [ ] Add `is_oauth` convenience boolean
- [ ] Block regenerate for OAuth tokens in TokenController (return 403)
- [ ] Include OAuth fields in regenerate/update response serialization

### Task 8: Update Vue components

- [ ] McpPage.vue: Show "OAuth · ClientName" badge next to token name, hide regenerate button when `token.is_oauth`
- [ ] McpAdminPage.vue: Same changes

### Task 9: Tests

- [ ] Update existing token store tests if they break from new params
- [ ] Run full test suite
- [ ] Run `composer quality`
