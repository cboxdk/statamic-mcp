# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Statamic addon that functions as an MCP (Model Context Protocol) server, built on top of Laravel's MCP server. The addon extends Statamic CMS v6.6+ and requires `laravel/mcp` ^0.6 as a runtime dependency. It includes scoped API token authentication, a Vue 3 CP dashboard, and web MCP endpoints.

## Key Dependencies

- **PHP**: ^8.3
- **Statamic CMS**: ^6.6 (v6 only — v5 support was removed in v2.0)
- **Laravel**: ^12.0 || ^13.0 (via Statamic v6)
- **Laravel MCP**: ^0.6 (required - must be in `require` section, not `require-dev`)
- **Orchestra Testbench**: ^10.0 || ^11.0 (dev dependency for testing)
- **Pest**: ^4.1 (stable release with PHP 8.3 requirement)
- **Symfony YAML**: ^7.0 || ^8.0 (for YAML processing)

## Authentication System

### Scoped API Tokens
The addon provides scoped API tokens for fine-grained MCP access control:

- **Token Management**: Via Statamic CP dashboard (Tools → MCP → Tokens)
- **Token Storage**: Eloquent model (`McpToken`) with SHA-256 hashed tokens
- **Guard**: Custom `McpTokenGuard` registered as the `mcp` auth guard
- **Scopes**: 21 granular scopes via `TokenScope` enum (e.g., `content:read`, `content:write`, `*`)

### Key Auth Classes
- `src/Auth/TokenScope.php` — Backed string enum with scope helpers
- `src/Auth/McpToken.php` — Eloquent model with UUID primary keys
- `src/Auth/TokenService.php` — Token CRUD, validation, and pruning
- `src/Auth/McpTokenGuard.php` — Laravel Guard implementation for Bearer tokens
- `src/Auth/AuthServiceProvider.php` — Registers singletons and auth guard

### Middleware
- `HandleMcpCors` — CORS headers for browser-based clients (only when `allowed_origins` configured)
- `EnsureSecureTransport` — Rejects plain HTTP in production (when `require_https` enabled)
- `AuthenticateForMcp` — Bearer token + Basic Auth fallback
- `RequireMcpPermission` — Validates token scopes and expiry

### Confirmation Tokens
Destructive MCP operations require a two-step confirmation flow in production:
- **ConfirmationTokenManager** (`src/Auth/ConfirmationTokenManager.php`) — Stateless HMAC-SHA256 tokens bound to tool + arguments
- **RequiresConfirmation trait** (`src/Mcp/Tools/Concerns/RequiresConfirmation.php`) — Integrated into BaseRouter
- **Operations requiring confirmation:** All `delete` actions + blueprint `create`/`update`/`delete`
- **Environment-aware:** Auto-enabled in production, disabled in local/dev/testing (configurable via `STATAMIC_MCP_CONFIRMATION_ENABLED`)
- **CLI bypass:** Confirmation is skipped in CLI context

### Resource Policy
Granular resource-level access control configured in `config/statamic/mcp.php`:
- **ResourcePolicy** (`src/Auth/ResourcePolicy.php`) — Glob-based resource allowlists + field deny lists
- **EnforcesResourcePolicy trait** (`src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php`) — Integrated into BaseRouter
- **Per-domain config:** `resources.read`/`resources.write` (glob patterns) + `denied_fields` (field names to strip)
- **Applies everywhere:** Resource policy is enforced in both CLI and web contexts (site-wide admin policy)
- **Field filtering:** Denied fields silently stripped from both input and output

### Authorization Evaluation Order (Web Context)
1. Tool enabled? → `config: tools.{domain}.enabled`
2. Token scope? → `TokenScope: {domain}:{read|write}`
3. Resource allowed? → `ResourcePolicy::canAccess(domain, handle, mode)`
4. Statamic permissions? → `User::hasPermission()`
5. Confirmation required? → `ConfirmationTokenManager` (deletes + blueprint writes)
6. Field filtering → `ResourcePolicy::filterFields()` on input + output

### OAuth 2.1 Authorization Server
The addon includes a full OAuth 2.1 authorization server with PKCE for browser-based MCP clients:

- **Dynamic Client Registration**: POST `/mcp/oauth/register` (RFC 7591)
- **Authorization Code Flow**: GET/POST `/{cp}/mcp/oauth/authorize` with PKCE (S256)
- **Token Exchange**: POST `/mcp/oauth/token` with authorization_code and refresh_token grants
- **Token Revocation**: POST `/mcp/oauth/revoke` (RFC 7009)
- **Discovery**: `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`

OAuth tokens store `oauth_client_id` and `oauth_client_name` for integration tracking. The dashboard shows an "OAuth" badge and hides the regenerate button for OAuth-created tokens.

### Key OAuth Classes
- `src/Http/Controllers/OAuth/AuthorizeController.php` — Consent screen and approval
- `src/Http/Controllers/OAuth/OAuthTokenController.php` — Token exchange (auth code + refresh)
- `src/Http/Controllers/OAuth/DiscoveryController.php` — OAuth metadata endpoints
- `src/Http/Controllers/OAuth/RegistrationController.php` — Dynamic client registration
- `src/OAuth/Contracts/OAuthDriver.php` — Driver interface (BuiltIn or Database)
- `src/Events/McpTokenSaved.php` — Git automation event for token changes
- `src/Events/McpTokenDeleted.php` — Git automation event for token deletion
- `src/OAuth/Concerns/ValidatesRedirectUris.php` — Shared redirect URI validation

### Storage Drivers
Tokens and audit logs support pluggable storage backends:
- `src/Storage/Tokens/FileTokenStore.php` — YAML flat-file storage (default)
- `src/Storage/Tokens/DatabaseTokenStore.php` — Eloquent/database storage
- `src/Storage/Audit/FileAuditStore.php` — JSONL flat-file audit log (default)
- `src/Storage/Audit/DatabaseAuditStore.php` — Database audit log
- `src/Storage/Tokens/McpTokenData.php` — Immutable DTO for token data

### Git Integration
Token operations dispatch events for Statamic's Git automation:
- `src/Events/McpTokenSaved.php` — Dispatched on create, update, regenerate
- `src/Events/McpTokenDeleted.php` — Dispatched on revoke

## Web MCP Endpoint

This addon supports web-accessible MCP endpoints for browser-based integrations. The web endpoint is enabled by default. See the configuration reference for customization options.

### Quick Setup

```env
# Web MCP endpoint (enabled by default)
STATAMIC_MCP_WEB_ENABLED=true
STATAMIC_MCP_WEB_PATH="/mcp/statamic"
```

The endpoint will be available at `https://your-site.test/mcp/statamic` with Bearer token or Basic Auth authentication.

### MCP Client Configuration

```json
{
    "mcpServers": {
        "statamic": {
            "url": "https://your-site.test/mcp/statamic",
            "headers": {
                "Authorization": "Bearer <your-mcp-token>"
            }
        }
    }
}
```

Tokens are created via the CP dashboard (Tools → MCP → Tokens) with specific scopes.

## Development Commands

### Testing
```bash
# Run all tests with Pest
./vendor/bin/pest
composer test

# Run a specific test file
./vendor/bin/pest tests/BlueprintsScanToolTest.php

# Run tests with coverage
./vendor/bin/pest --coverage
composer test:coverage

# Run tests in watch mode
./vendor/bin/pest --watch
```

### Code Quality
```bash
# Format code with Laravel Pint
./vendor/bin/pint
composer pint

# Check code formatting without fixing
./vendor/bin/pint --test
composer pint:test

# Run static analysis with PHPStan/Larastan
./vendor/bin/phpstan analyse
composer stan

# Run all quality checks (format + analysis + tests)
composer quality
```

**IMPORTANT**: All code files MUST pass PHPStan Level 8 analysis with zero errors. This project maintains the highest code quality standards:

- **Type Safety**: All methods must have proper type annotations (`@param`, `@return`)
- **Strict Types**: All PHP files must declare `strict_types=1`
- **No Mixed Types**: Avoid `mixed` types - use specific array shapes like `array<string, mixed>`
- **Method Signatures**: All method parameters and return types must be explicitly typed

New tool files must include proper PHPDoc annotations:
```php
/**
 * @param  array<string, mixed>  $arguments
 *
 * @return array<string, mixed>
 */
protected function executeInternal(array $arguments): array
```

### Composer
```bash
# Install dependencies
composer install

# Update dependencies
composer update

# Dump autoload files
composer dump-autoload
```

## Architecture

### Service Provider
The main entry point is `src/ServiceProvider.php` which extends `Statamic\Providers\AddonServiceProvider`. The `bootAddon()` method is where MCP server functionality and Statamic integrations should be implemented.

### Testing Structure
- Tests extend `Cboxdk\StatamicMcp\Tests\TestCase` which extends `Statamic\Testing\AddonTestCase`
- Test configuration is defined in `phpunit.xml` with environment variables for Laravel/Statamic testing
- The TestCase base class automatically registers the addon's ServiceProvider

### Addon Registration
The addon is registered through Laravel's service provider discovery mechanism via the `extra.laravel.providers` configuration in `composer.json`.

## Laravel MCP v0.6 Tool Development Guide

**CRITICAL**: This project uses Laravel MCP v0.6 which has specific patterns that MUST be followed exactly.

### Required Tool Structure

All tools MUST extend `BaseStatamicTool` which provides standardized error handling and MCP compliance. Tools use `#[Name]` and `#[Description]` attributes (not methods):

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Domain;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly; // Optional

#[Name('statamic-domain-action')]
#[Description('Clear description of what this tool does')]
#[IsReadOnly] // Optional annotation
class ExampleTool extends BaseStatamicTool
{
    protected function defineSchema(JsonSchema $schema): array
    {
        return [
            'required_field' => JsonSchema::string()
                ->description('Field description')
                ->required(),
            'optional_field' => JsonSchema::boolean()
                ->description('Optional field description'),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        // Tool implementation
        return ['result' => 'data'];
    }
}
```

### Key Differences from v0.2.0

- **Attributes instead of methods**: Use `#[Name('...')]` and `#[Description('...')]` instead of `getToolName()`/`getToolDescription()`
- **Tool extends Primitive**: `Tool` base class provides `name()` and `description()` via attributes or string properties
- **Response objects**: `handle()` returns `Response|ResponseFactory`. Use `Response::structured(array)` for success and `Response::error(string)` for errors
- **BaseStatamicTool wraps this**: `executeInternal()` is the abstract method tools implement; `handle()` and `schema()` are handled by BaseStatamicTool

### Schema Definition

```php
protected function defineSchema(JsonSchema $schema): array
{
    return [
        'field' => JsonSchema::string()
            ->description('Field description')
            ->required(),
        'optional' => JsonSchema::boolean()
            ->description('Optional field'),
    ];
}
```

### Schema Field Types

Available JsonSchema field types:
- `JsonSchema::string()` - Text input
- `JsonSchema::boolean()` - True/false
- `JsonSchema::integer()` - Whole numbers
- `JsonSchema::number()` - Decimal numbers
- `JsonSchema::array()` - Arrays
- `JsonSchema::object()` - Objects

### Required Method Signatures

```php
// Provided via #[Name] and #[Description] attributes — no methods needed
abstract protected function defineSchema(JsonSchema $schema): array;
abstract protected function executeInternal(array $arguments): array;
```

### Error Handling

Use BaseStatamicTool's standardized error methods:

```php
// Success response
return $this->createSuccessResponse($data);

// Error response
return $this->createErrorResponse('Error message')->toArray();

// Not found response
return $this->createNotFoundResponse('Blueprint', $handle)->toArray();
```

### Tool Annotations

Available annotations/attributes:
- `#[Name('tool-name')]` - Tool name (required)
- `#[Description('...')]` - Tool description (required)
- `#[IsReadOnly]` - Tool only reads data
- `#[IsIdempotent]` - Tool can be called multiple times safely

### Important Implementation Notes

1. MCP server configurations and handlers are registered in the ServiceProvider
2. **NEVER override the `handle()` method** — BaseStatamicTool handles execution
3. Tool names use hyphens: `statamic-blueprints`, `statamic-entries` (not dots)
4. All tools must validate token scopes when accessed via web endpoint

### Validation Rules

✅ **DO:**
- Use `#[Name]` and `#[Description]` attributes on tool classes
- Return arrays from `defineSchema()`
- Implement `executeInternal()` (not `execute()` or `handle()`)
- Use `BaseStatamicTool` error response methods
- Include proper PHPDoc type annotations
- Use tool annotations for behavior (`#[IsReadOnly]`)

❌ **DON'T:**
- Define `getToolName()` or `getToolDescription()` methods
- Override the `handle()` method
- Use dot-separated tool names (use hyphens)
- Import or use `ToolResult` or `ToolInputSchema` classes

## CRITICAL: No Hardcoded Templates

**NEVER HARDCODE BLUEPRINT TEMPLATES OR FIELD STRUCTURES** - This is a fundamental architectural principle.

### What This Means
- **NO hardcoded blueprint definitions** in PHP methods like `getBlogFields()`, `getEcommerceFields()`, etc.
- **NO predefined field structures** in any tool that generates blueprints
- **NO template methods** that return fixed field arrays

### Why This Matters
- **MCP Architecture**: Tools provide deterministic data access, LLM provides reasoning
- **User Control**: The LLM (based on user input) defines what fields/structures are needed
- **Flexibility**: Every project has different requirements - no assumptions should be made
- **Separation of Concerns**: Tools validate and create based on input, they don't define content

### Correct Approach
- Tools should **accept** field definitions from user/LLM input
- Tools should **validate** field structures against Statamic schema
- Tools should **create/update** blueprints based on provided definitions
- All field structures come from **dynamic user input**, never hardcoded arrays

### Example - WRONG:
```php
private function getBlogFields(): array {
    return [
        'title' => ['type' => 'text'],
        'content' => ['type' => 'markdown'],
        // ... hardcoded fields
    ];
}
```

### Example - CORRECT:
```php
private function createBlueprint(array $userDefinedFields): array {
    // Validate user input and create blueprint
    return $this->processFieldDefinitions($userDefinedFields);
}
```

**Remember**: The LLM defines WHAT to create, tools handle HOW to create it safely.

## Project Goals

This MCP server aims to improve developer experience for both Antlers and Blade templates in Statamic by:
- Providing autocomplete, validation, and type generation based on blueprints/fieldsets
- Enforcing best practices in Blade templates (avoiding inline PHP, facades, and direct DB calls)
- Promoting use of Statamic tags and components over raw PHP
- Generating fixtures and validating references for testing

## MCP Architecture Principles

This project follows strict separation of concerns between MCP tools, LLM, and prompts:

### MCP Tools (Deterministic Capabilities)
**Responsibility**: Deterministic, reproducible access to data and actions.

**Must Do**:
- Read/validate/serialize Statamic artifacts (blueprints, fields, collections, taxonomies, navigations, forms, globals, users)
- Execute safe operations: list, get, diff, validate, search, resolve-reference, generate-scaffold (copy-safe)
- Return structured responses (JSON/YAML) with schema/types – **never free text**
- Enforce domain rules: "no inline PHP in Blade", "use Statamic tags/components", "antlers/blade tags whitelist"
- Idempotent and side-effect control: only "write" when explicit tool called (e.g., applyBlueprintPatch)
- Include observability: version info, checksum, paths, warnings

**Must Not Do**:
- Generate free-text explanations, guess, prioritize ideas, or conclude without data
- Write to disk without explicit write tool calls
- Make assumptions about project structure
- Return unstructured text responses

### LLM (Reasoning and Advisory)
**Responsibility**: Reasoning, advisory, summarization, suggestions.

**Must Do**:
- Interpret developer intent and convert to safe tool calls
- Compile multiple tool results (e.g., compare blueprints across folders)
- Give recommendations ("use {{ collection:... }} here, avoid facade calls")
- Generate code/markup only with domain rules from tools (e.g., component skeletons)
- Explain validation errors and suggest patches

**Must Not Do**:
- Read files, "find" paths, guess schemas without tools
- Be source of truth for data without tool verification
- Bypass tool validation or security policies

### Prompts (Instructions/Policies)
**Responsibility**: Control and guardrails.

**Must Define**:
- Policy: "In Blade you may only use Statamic tags/components, no PHP or facades"
- Format: "Always call getBlueprints before suggesting field usage"
- Order: discovery → validate → (optional) patch → confirm
- Constraints: "Must not write to disk without explicit confirmation"
- Output contracts: "Suggestions returned as JSON Patch + human summary"

### Security & Quality Standards
- **Least privilege**: Tools limited to /resources/blueprints, /resources/views
- **Dry-run**: All write-tools support dryRun: true with diff/patch
- **Backups**: Automatic .bak/git-stash before write
- **Version awareness**: Report Statamic/Laravel version in every response
- **Rate limiting**: Protect against chat-loops
- **Determinism**: Same input → same output

### Required Output Contract for All Tools

Success response:
```json
{
  "success": true,
  "data": {...},
  "meta": {
    "statamic_version": "6.0.0",
    "laravel_version": "12.0",
    "timestamp": "2025-01-01T12:00:00Z",
    "tool": "tool_name"
  }
}
```

Error response:
```json
{
  "success": false,
  "error": "Human-readable error message",
  "code": "MACHINE_READABLE_CODE"
}
```

### Development Flow
1. **Discovery**: LLM calls read/list tools
2. **Validation**: LLM calls validate tools
3. **Suggestion**: LLM generates patch/changes using tool data
4. **Confirmation**: User explicitly approves
5. **Execution**: LLM calls write tools
6. **Verification**: LLM calls validate tools again

## MCP Tools Architecture

The MCP server is organized around **domain router tools** following the Router Pattern. Each router handles all operations for its domain via an `action` parameter.

### Tool Naming Convention
**Format**: `statamic-{domain}`

Where:
- `domain` = The Statamic concept (blueprints, entries, terms, globals, etc.)
- Actions are passed as a parameter, not encoded in the tool name

### Modern Tool Architecture: Router Pattern

**EVOLUTION**: This project has evolved from 140+ single-purpose tools to a **router-based architecture** for better scalability and maintainability.

#### 🎯 Router Pattern Benefits:
- **Reduced Tool Count**: Group related operations into domain routers
- **Better Organization**: Clear domain boundaries and action routing
- **Simplified LLM Selection**: Fewer tools to choose from, clearer purposes
- **Easier Maintenance**: Single file per domain instead of scattered files
- **Performance**: Reduced overhead and faster tool loading

### Router Tool Structure

Each domain has a **single router tool** that handles all operations within that domain:

```php
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('statamic-blueprints')]
#[Description('Manage Statamic blueprints: list, get, create, update, delete, scan, generate, and analyze')]
class BlueprintsRouter extends BaseStatamicTool
{
    protected function defineSchema(JsonSchema $schema): array
    {
        return [
            'action' => JsonSchema::string()
                ->description('Action to perform')
                ->enum(['list', 'get', 'create', 'update', 'delete', 'scan', 'generate', 'types'])
                ->required(),
            'handle' => JsonSchema::string()
                ->description('Blueprint handle (required for get, update, delete)'),
            'namespace' => JsonSchema::string()
                ->description('Blueprint namespace (collections, taxonomies, globals)'),
            'fields' => JsonSchema::array()
                ->description('Field definitions for create/update operations'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed field information'),
            'output_format' => JsonSchema::string()
                ->description('Output format for types action (typescript, php, json-schema)')
                ->enum(['typescript', 'php', 'json-schema', 'all']),
        ];
    }

    protected function executeInternal(array $arguments): array
    {
        $action = $arguments['action'];

        return match ($action) {
            'list' => $this->listBlueprints($arguments),
            'get' => $this->getBlueprint($arguments),
            'create' => $this->createBlueprint($arguments),
            'update' => $this->updateBlueprint($arguments),
            'delete' => $this->deleteBlueprint($arguments),
            'scan' => $this->scanBlueprints($arguments),
            'generate' => $this->generateBlueprint($arguments),
            'types' => $this->analyzeTypes($arguments),
            default => $this->createErrorResponse("Unknown action: {$action}")->toArray(),
        };
    }

    private function listBlueprints(array $arguments): array
    {
        // Implementation for listing blueprints
    }

    private function getBlueprint(array $arguments): array
    {
        // Implementation for getting a specific blueprint
    }

    // ... other action methods
}
```

### Architectural Patterns

#### 1. 🏗️ **Facade Pattern** (for commonly used combinations)

```php
// Uses #[Name('statamic-content-facade')] and #[Description('...')] attributes
class ContentFacadeRouter extends BaseRouter
{
    protected function defineSchema(JsonSchema $schema): array
    {
        return [
            'action' => JsonSchema::string()
                ->description('Action to perform')
                ->enum(['content_audit', 'cross_reference'])
                ->required(),
            'filters' => JsonSchema::object()
                ->description('Optional filter conditions to narrow the workflow scope'),
        ];
    }

    protected function executeInternal(array $arguments): array
    {
        return match ($arguments['action']) {
            'content_audit' => $this->contentAudit($arguments),
            'cross_reference' => $this->crossReference($arguments),
        };
    }

    private function contentAudit(array $arguments): array
    {
        // Scans all content for issues across collections, taxonomies, and globals
        // Returns validation issues, missing references, orphaned content
    }

    private function crossReference(array $arguments): array
    {
        // Analyzes relationships and dependencies between content types
        // Returns relationship maps, dependency graphs, integrity checks
    }
}
```

#### 2. 🔗 **Chain of Responsibility** (for prioritized operations)

```php
// Uses #[Name('statamic-validation-chain')] and #[Description('...')] attributes
class StatamicValidationChain extends BaseStatamicTool
{
    protected function executeInternal(array $arguments): array
    {
        $validators = [
            new BlueprintValidator(),
            new FieldValidator(),
            new RelationshipValidator(),
            new SecurityValidator(),
        ];

        $results = ['passed' => [], 'failed' => [], 'warnings' => []];

        foreach ($validators as $validator) {
            $result = $validator->validate($arguments['data']);

            if ($result['critical_failure']) {
                // Stop chain on critical failure
                return $this->createErrorResponse($result['message'])->toArray();
            }

            $results['passed'][] = $validator->getName();
            $results['warnings'] = array_merge($results['warnings'], $result['warnings']);
        }

        return $results;
    }
}
```

#### 3. 🎯 **Strategy Pattern** (for different implementations)

```php
// Uses #[Name('statamic-export-strategy')] and #[Description('...')] attributes
class StatamicExportStrategy extends BaseStatamicTool
{
    protected function defineSchema(JsonSchema $schema): array
    {
        return [
            'strategy' => JsonSchema::string()
                ->enum(['json', 'yaml', 'csv', 'xml'])
                ->required(),
            'collection' => JsonSchema::string()->required(),
            'format_options' => JsonSchema::object()
                ->description('Strategy-specific formatting options'),
        ];
    }

    protected function executeInternal(array $arguments): array
    {
        $strategy = $this->getExportStrategy($arguments['strategy']);

        return $strategy->export(
            $arguments['collection'],
            $arguments['format_options'] ?? []
        );
    }

    private function getExportStrategy(string $type): ExportStrategyInterface
    {
        return match ($type) {
            'json' => new JsonExportStrategy(),
            'yaml' => new YamlExportStrategy(),
            'csv' => new CsvExportStrategy(),
            'xml' => new XmlExportStrategy(),
        };
    }
}
```

### Tool Organization (v2.0)

#### Domain Routers (Core Tools — MCP tool names use hyphens)
- `statamic-blueprints` - Blueprint management router
- `statamic-entries` - Entry CRUD with filtering and publishing workflows
- `statamic-terms` - Taxonomy term management router
- `statamic-globals` - Global set structure and values router
- `statamic-structures` - Structural elements router (collections, taxonomies, navigations)
- `statamic-assets` - Asset management router
- `statamic-users` - User management router
- `statamic-system` - System operations router

#### Agent Education Tools
- `statamic-system-discover` - Intent-based tool discovery
- `statamic-system-schema` - Tool schema inspection

#### Workflow Facades
- `statamic-content-facade` - Common content workflows

### Benefits of Router Architecture:
1. **Scalability**: Easy to add new actions without new tools
2. **Maintainability**: Single file per domain reduces fragmentation
3. **Performance**: Fewer tools to load and choose from
4. **Clarity**: Clear domain boundaries and action routing
5. **Testing**: Easier to test complete domain functionality
6. **Documentation**: Single place for all domain operations

### Router Actions Reference

**`statamic-blueprints`** — list, get, create, update, delete, scan, generate, types, validate

**`statamic-entries`** — list, get, create, update, delete, publish, unpublish

**`statamic-terms`** — list, get, create, update, delete

**`statamic-globals`** — list, get, update (global set values with multi-site support)

**`statamic-structures`** — list/get/create for collections, taxonomies, navigations, and sites (via `type` param)

**`statamic-assets`** — list/get/create/update/delete/upload/move/copy for containers and assets (via `type` param)

**`statamic-users`** — list, get, create, update, delete, assign-role for users, roles, and groups (via `type` param)

**`statamic-system`** — system info, health checks, cache management, config access (via `type` param)

**`statamic-content-facade`** — high-level analysis workflows: content_audit, cross_reference

**`statamic-system-discover`** — intent-based tool and action discovery

**`statamic-system-schema`** — inspect full JSON schema of any registered tool

## Production-Ready Features

### Advanced Template Analysis & Optimization
**OptimizedTemplateAnalyzer** - Comprehensive template performance and security analysis:

**Performance Detection**:
- **N+1 Query Problems**: Detects collection queries inside loops
- **Nested Loop Analysis**: Identifies deeply nested loops with complexity scoring
- **Excessive Partials**: Warns about performance impact of too many partial includes
- **Missing Pagination**: Detects large collections without pagination
- **Complex Conditionals**: Identifies overly complex template logic
- **Uncached Dynamic Content**: Finds content that prevents full-page caching

**Edge Case Detection**:
- **Recursive Partials**: Prevents infinite loops from self-referencing partials
- **Memory-Intensive Operations**: Detects potentially memory-exhausting operations
- **XSS Vulnerabilities**: Identifies unescaped HTML output risks
- **Infinite Loop Risks**: Detects while loops and unbounded iterations
- **Large Dataset Issues**: Warns about operations that may cause timeouts

**Integration Features**:
- **Integrated into all template tools**: AntlersValidateTool, BladeLintTool, TemplatesDevelopmentTool
- **Severity-based prioritization**: Critical, High, Medium, Low issue categorization
- **Actionable optimization suggestions**: Specific recommendations with code examples
- **Performance metrics**: Complexity scoring and estimated render time impact

### Comprehensive Global Management
**Global Sets vs Global Values** - Clear architectural separation:

**Structure Management (Global Sets)**:
- Blueprint-based field definitions and validation
- Multi-site configuration and localization setup
- Creation, deletion, and structural modifications
- Sample data generation based on field types

**Content Management (Global Values)**:
- Site-specific content editing and updates
- Field-level filtering and partial updates
- Change tracking with before/after comparisons
- Merge vs replace update strategies
- Validation against blueprint field definitions

### Automatic Cache Purging
All structural and content changes automatically clear relevant caches:
- **Blueprint changes**: Clears stache, static, views
- **Content changes**: Clears stache, static
- **Structure changes**: Clears stache, static, views
- **Global changes**: Clears stache with targeted cache invalidation
- **Response includes cache status** for transparency

### Performance Optimizations
- **Pagination support** in all content extraction tools
- **Field filtering** in blueprint scanning (`include_fields: false`)
- **Response size limits** to prevent token overflow (< 25,000 tokens)
- **Intelligent defaults** for large datasets
- **Optimized Site validation** using `Site::all()->map->handle()->contains()`
- **Collection handle caching** with `Collection::handles()->all()`

## Code Quality Standards

This project maintains high code quality through automated tools:

### Laravel Pint
- **Configuration**: `pint.json` (Laravel preset with custom rules)
- **Purpose**: Automatic code formatting and style consistency
- **Run**: `composer pint` to format code, `composer pint:test` to check without fixing

### Larastan (PHPStan for Laravel)
- **Configuration**: `phpstan.neon` (Level 8 analysis)
- **Purpose**: Static analysis for type safety and bug detection
- **Features**: Statamic-specific stubs and Laravel integration
- **Run**: `composer stan` to analyze code
- **Status**: Zero errors — all files pass Level 8 analysis

### Quality Assurance Workflow
```bash
# Complete quality check pipeline
composer quality  # Runs: pint + stan + test
```

### Pre-commit Recommendations
1. Run `composer pint` before committing
2. Ensure `composer stan` passes with no errors
3. Verify all tests pass with `composer test`
4. Use `composer quality` for complete validation

## Quick Reference: Router Pattern Templates

### Domain Router Template

Use this template when creating domain router tools:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('statamic-domain')]
#[Description('Manage domain resources: list, get, create, update, delete operations')]
class DomainRouter extends BaseStatamicTool
{
    protected function defineSchema(JsonSchema $schema): array
    {
        return [
            'action' => JsonSchema::string()
                ->description('Action to perform')
                ->enum(['list', 'get', 'create', 'update', 'delete'])
                ->required(),
            'handle' => JsonSchema::string()
                ->description('Resource handle (required for get, update, delete)'),
            'data' => JsonSchema::array()
                ->description('Resource data for create/update operations'),
            'filters' => JsonSchema::object()
                ->description('Filtering options for list operations'),
        ];
    }

    /**
     * Route actions to appropriate handlers.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        $action = $arguments['action'];

        // Validate action-specific requirements
        if (in_array($action, ['get', 'update', 'delete']) && empty($arguments['handle'])) {
            return $this->createErrorResponse("Handle is required for {$action} action")->toArray();
        }

        return match ($action) {
            'list' => $this->list($arguments),
            'get' => $this->get($arguments),
            'create' => $this->create($arguments),
            'update' => $this->update($arguments),
            'delete' => $this->delete($arguments),
            default => $this->createErrorResponse("Unknown action: {$action}")->toArray(),
        };
    }

    private function list(array $arguments): array
    {
        // Implementation for listing resources
        return ['resources' => []];
    }

    private function get(array $arguments): array
    {
        // Implementation for getting a specific resource
        return ['resource' => []];
    }

    private function create(array $arguments): array
    {
        // Implementation for creating a resource
        return ['created' => true];
    }

    private function update(array $arguments): array
    {
        // Implementation for updating a resource
        return ['updated' => true];
    }

    private function delete(array $arguments): array
    {
        // Implementation for deleting a resource
        return ['deleted' => true];
    }
}
```

### Workflow Facade Template

Use this template for common workflow combinations:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Workflows;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\JsonSchema\JsonSchema;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('statamic-domain-workflow')]
#[Description('Execute common domain workflows: setup, import, export, audit')]
class WorkflowFacade extends BaseStatamicTool
{
    protected function defineSchema(JsonSchema $schema): array
    {
        return [
            'workflow' => JsonSchema::string()
                ->description('Workflow to execute')
                ->enum(['setup', 'import', 'export', 'audit'])
                ->required(),
            'config' => JsonSchema::object()
                ->description('Workflow-specific configuration'),
            'data' => JsonSchema::array()
                ->description('Data for the workflow'),
        ];
    }

    protected function executeInternal(array $arguments): array
    {
        return match ($arguments['workflow']) {
            'setup' => $this->executeSetup($arguments),
            'import' => $this->executeImport($arguments),
            'export' => $this->executeExport($arguments),
            'audit' => $this->executeAudit($arguments),
            default => $this->createErrorResponse("Unknown workflow: {$arguments['workflow']}")->toArray(),
        };
    }

    private function executeSetup(array $arguments): array
    {
        // Orchestrate multiple operations for setup
        $results = [];

        // Step 1: Create structure
        $results['structure'] = $this->createStructure($arguments['config']);

        // Step 2: Setup content
        $results['content'] = $this->setupContent($arguments['data']);

        // Step 3: Configure settings
        $results['settings'] = $this->configureSettings($arguments['config']);

        return $results;
    }

    // ... other workflow methods
}
```

### Strategy Pattern Template

Use this template for configurable implementations:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Strategies;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\JsonSchema\JsonSchema;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('statamic-domain-strategy')]
#[Description('Process data using configurable strategies')]
class StrategyTool extends BaseStatamicTool
{
    protected function defineSchema(JsonSchema $schema): array
    {
        return [
            'strategy' => JsonSchema::string()
                ->description('Strategy to use')
                ->enum(['strategy_a', 'strategy_b', 'strategy_c'])
                ->required(),
            'input' => JsonSchema::array()
                ->description('Input data for processing')
                ->required(),
            'options' => JsonSchema::object()
                ->description('Strategy-specific options'),
        ];
    }

    protected function executeInternal(array $arguments): array
    {
        $strategy = $this->getStrategy($arguments['strategy']);

        return $strategy->process(
            $arguments['input'],
            $arguments['options'] ?? []
        );
    }

    private function getStrategy(string $type): StrategyInterface
    {
        return match ($type) {
            'strategy_a' => new ConcreteStrategyA(),
            'strategy_b' => new ConcreteStrategyB(),
            'strategy_c' => new ConcreteStrategyC(),
            default => throw new \InvalidArgumentException("Unknown strategy: {$type}"),
        };
    }
}
```

## Configuration

The addon supports configuration via `config/statamic/mcp.php` for:
- Primary templating language (Antlers/Blade preference)
- Blade policy rules (forbidden patterns, preferred approaches)
- Web MCP endpoint settings
- API token configuration
- Rate limiting and audit logging
- Per-domain tool enablement
- OAuth 2.1 settings (driver, TTLs, default scopes, max clients)
- Storage drivers (FileTokenStore/DatabaseTokenStore, FileAuditStore/DatabaseAuditStore)
- Storage paths for tokens, audit, and OAuth data
- Tool env toggles (`STATAMIC_MCP_TOOL_{NAME}_ENABLED`)
- Git automation events for token operations
