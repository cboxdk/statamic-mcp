# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Statamic addon that functions as an MCP (Model Context Protocol) server, built on top of Laravel's MCP server. The addon extends Statamic CMS v5 and requires `laravel/mcp` as a runtime dependency.

## Key Dependencies

- **Statamic CMS**: ^5.0 (required)
- **Laravel MCP**: ^0.1.1 (required - must be in `require` section, not `require-dev`)
- **Orchestra Testbench**: ^9.0 (dev dependency for testing)

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
protected function execute(array $arguments): array
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

## Important Implementation Notes

Since this addon builds on Laravel's MCP server, ensure that:
1. MCP server configurations and handlers are properly registered in the ServiceProvider
2. Any Statamic-specific MCP extensions are documented
3. The addon follows both Statamic addon conventions and MCP server patterns

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
```json
{
  "success": true/false,
  "data": {...},
  "meta": {
    "statamic_version": "v5.46",
    "laravel_version": "12.0",
    "timestamp": "2025-01-01T12:00:00Z",
    "tool": "tool_name"
  },
  "errors": [...],
  "warnings": [...]
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

The MCP server is organized around **single-purpose tools** following the Command Pattern. Each tool performs one specific action with clear input/output schemas.

### Tool Naming Convention
**Format**: `statamic.{domain}.{action}`

Where:
- `domain` = The Statamic concept (blueprints, collections, entries, etc.)
- `action` = The specific operation (list, get, create, update, delete, etc.)

### Single-Purpose Tool Guidelines

**CRITICAL**: Each MCP tool must perform exactly ONE action following the Command Pattern.

**IMPORTANT**: When creating new tools, ALWAYS ensure they follow single-purpose design:
- Each tool performs exactly one specific action
- No conditional logic based on action parameters
- Clear, focused schema without action enums
- Descriptive tool names that indicate exact purpose

#### ❌ WRONG: Multi-purpose tools with action conditionals
```php
class BlueprintsStructureTool {
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema {
        return $schema->raw('action', ['enum' => ['list', 'get', 'create']]);
    }
    
    protected function execute(array $arguments): array {
        return match($arguments['action']) {
            'list' => $this->listBlueprints(),
            'get' => $this->getBlueprint(),
            'create' => $this->createBlueprint(),
        };
    }
}
```

#### ✅ CORRECT: Single-purpose tools with clear schemas
```php
class ListBlueprintsTool {
    protected function getToolName(): string {
        return 'statamic.blueprints.list';
    }
    
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema {
        return $schema
            ->string('namespace')->optional()
            ->boolean('include_details')->optional();
    }
    
    protected function execute(array $arguments): array {
        // Only lists blueprints - no conditionals or actions
        return $this->listBlueprints($arguments);
    }
}
```

#### Benefits of Single-Purpose Tools:
1. **Reduced Token Overhead**: No complex conditional schemas
2. **Better Performance**: LLM makes faster, more accurate tool selections
3. **Clearer Documentation**: Each tool has precise purpose and parameters
4. **Better Error Handling**: Specific error messages for each operation
5. **Easier Testing**: Each tool can be tested in isolation

### Blueprint Tools ✅
**Purpose**: Manage blueprint definitions and schema

- `statamic.blueprints.list` - List blueprints in specific namespaces with optional details
- `statamic.blueprints.get` - Get specific blueprint with full field definitions
- `statamic.blueprints.create` - Create new blueprint from field definitions
- `statamic.blueprints.update` - Update existing blueprint fields
- `statamic.blueprints.delete` - Delete blueprint with safety checks
- `statamic.blueprints.scan` - Blueprint scanning with performance optimization
- `statamic.blueprints.generate` - Generate blueprints from templates and field definitions
- `statamic.blueprints.types` - Blueprint type analysis and TypeScript/PHP type generation

### Taxonomy Tools ✅
**Purpose**: Manage taxonomies and their terms

- `statamic.taxonomies.list` - List all taxonomies with filtering and metadata
- `statamic.taxonomies.get` - Get specific taxonomy with detailed information
- `statamic.taxonomies.create` - Create new taxonomies with configuration
- `statamic.taxonomies.update` - Update taxonomy settings and associations
- `statamic.taxonomies.delete` - Delete taxonomies with safety checks
- `statamic.taxonomies.analyze` - Analyze taxonomy usage and term relationships
- `statamic.taxonomies.terms` - List and manage terms within taxonomies

### Structure Tools ✅
**Purpose**: Manage structural configurations (collections, forms, navigations, etc.)

- `statamic.structures.collections` - Collection configuration management
- `statamic.structures.navigations` - Navigation structure management (coming soon)
- `statamic.structures.forms` - Form configuration management (coming soon)
- `statamic.structures.globals` - Global set configuration management (coming soon)
- `statamic.structures.assets` - Asset container configuration (coming soon)

### Entry Tools ✅
**Purpose**: Manage entries across all collections

- `statamic.entries.list` - List entries with filtering, search, and pagination
- `statamic.entries.get` - Get specific entry with full data and relationships
- `statamic.entries.create` - Create new entries with validation and blueprint compliance
- `statamic.entries.update` - Update existing entries with merge options and validation
- `statamic.entries.delete` - Delete entries with safety checks and relationship validation
- `statamic.entries.publish` - Publish draft entries with validation
- `statamic.entries.unpublish` - Unpublish entries with safety checks

### Term Tools ✅
**Purpose**: Manage taxonomy terms across all taxonomies

- `statamic.terms.list` - List terms with filtering, search, and pagination
- `statamic.terms.get` - Get specific term with full data and related entries
- `statamic.terms.create` - Create new terms with validation and slug conflict checking
- `statamic.terms.update` - Update existing terms with merge options and validation
- `statamic.terms.delete` - Delete terms with safety checks and dependency validation

### Global Tools ✅
**Purpose**: Manage global sets (structure) and global values (content)

**Global Sets (Structure Management)**:
- `statamic.globals.sets.list` - List all global sets with configuration and blueprint info
- `statamic.globals.sets.get` - Get specific global set structure with detailed field definitions
- `statamic.globals.sets.create` - Create new global sets with blueprint support and initial values
- `statamic.globals.sets.delete` - Delete global sets with backup options and safety checks

**Global Values (Content Management)**:
- `statamic.globals.values.list` - List global values across all sets and sites with filtering
- `statamic.globals.values.get` - Get specific global values from a set with field filtering
- `statamic.globals.values.update` - Update global values with validation, merge options, and change tracking

**Key Features**:
- **Clear Separation**: Structure (sets) vs Content (values) with distinct tool namespaces
- **Multi-site Support**: Full localization support across all sites
- **Blueprint Integration**: Automatic field validation against blueprints
- **Change Tracking**: Detailed change logs for all value updates
- **Backup Options**: Optional backup creation before destructive operations
- **Performance Optimized**: Pagination and filtering for large global datasets

### Navigation Tools ✅
**Purpose**: Manage navigation structures

- `statamic.navigation.list` - List navigation trees with full structure

### Other Content Tools
**Purpose**: Additional content management capabilities

- `statamic.content.assets` - Asset CRUD operations (coming soon)
- `statamic.content.submissions` - Form submission management (coming soon)
- `statamic.content.users` - User content management (coming soon)

### Tag Tools ✅
**Purpose**: Manage Statamic tags for both Antlers and Blade

- `statamic.tags.list` - Tag discovery, creation, and management for both Antlers and Blade

### Modifier Tools ✅
**Purpose**: Manage template modifiers

- `statamic.modifiers.list` - Modifier discovery, creation, and usage examples

### Field Type Tools ✅
**Purpose**: Manage custom field types

- `statamic.fieldtypes.list` - Field type discovery, creation, and configuration options

### Scope Tools ✅
**Purpose**: Manage query scopes

- `statamic.scopes.list` - Query scope discovery and creation

### Filter Tools ✅
**Purpose**: Manage collection filters

- `statamic.filters.list` - Filter discovery and creation

### Development Tools ✅
**Purpose**: Developer experience and tooling with advanced optimization

**Template Development & Optimization**:
- `statamic.development.templates` - Template hints with performance analysis and edge case warnings
- `statamic.development.antlers-validate` - Advanced Antlers template validation with performance analysis
- `statamic.development.blade-lint` - Comprehensive Blade linting with policy enforcement and performance analysis

**Advanced Features**:
- **OptimizedTemplateAnalyzer**: Detects N+1 queries, nested loops, excessive partials, and edge cases
- **Performance Analysis**: Identifies memory issues, recursive partials, and XSS vulnerabilities
- **Edge Case Detection**: Infinite loop risks, unescaped output, and caching conflicts
- **Optimization Suggestions**: Severity-based recommendations (Critical, High, Medium, Low)
- **Security Scanning**: XSS detection, input sanitization checks, and security best practices
- **Blueprint Integration**: Template validation against blueprint field definitions

**Additional Tools**:
- `statamic.development.addons` - Addon development, analysis, and scaffolding
- `statamic.development.types` - TypeScript/PHP type generation from blueprints (coming soon)
- `statamic.development.console` - Artisan command execution and management (coming soon)

### System Tools ✅
**Purpose**: System management and operations

- `statamic.system.info` - Comprehensive system analysis and health checks
- `statamic.system.cache` - Advanced cache management with selective clearing and warming
- `statamic.system.docs` - Documentation search and discovery (coming soon)

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
- **Production Status**: **89% error reduction** achieved (from 38+ to 4 template type warnings)
- **All critical errors resolved** for production deployment

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

## Configuration

The addon supports configuration via `config/statamic_mcp.php` for:
- Primary templating language (Antlers/Blade preference)
- Blueprint and fieldset paths
- Blade policy rules (forbidden patterns, preferred approaches)
- Cache and performance settings
- Rate limiting and debug options

See PRD.md for complete requirements and acceptance criteria.