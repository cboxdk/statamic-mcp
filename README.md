# Statamic MCP Server

A comprehensive MCP (Model Context Protocol) server for Statamic CMS that provides AI assistants with structured access to Statamic's content management capabilities through a clean, organized tool architecture.

> [!WARNING]
> **🚧 Alpha Software - Expect Bugs!**
> 
> This MCP server is currently in **alpha stage**. With over 100+ tools available, many of which were AI-generated, comprehensive testing is an ongoing process that takes time.
> 
> **What to expect:**
> - 🐛 **Bugs and errors** - This is alpha software, things will break
> - 🤖 **AI-generated tools** - Some tools were created with AI assistance and may have edge cases
> - 🔧 **Ongoing improvements** - We're actively testing and refining all tools
> - 📈 **Rapid iteration** - Frequent updates as we discover and fix issues
> 
> **How you can help:**
> - 🧪 **Test the tools** in your Statamic projects
> - 🐞 **Report bugs** by [creating issues](https://github.com/cboxdk/statamic-mcp/issues)
> - 🎯 **Submit PRs** with fixes and improvements
> - 💬 **Share feedback** on what works and what doesn't
> 
> Your testing and contributions help make this tool better for everyone! 🙏

## 📋 Requirements

- PHP 8.2+
- Laravel 11+
- Statamic 5.0+

## 🚀 Installation

```bash
# Install via Composer
composer require cboxdk/statamic-mcp

# Run the installation command to set up MCP
php artisan mcp:statamic:install

# The addon automatically registers with Laravel's MCP server
```

### 🎯 Recommended: Laravel Boost Integration

We strongly recommend installing **Laravel Boost** alongside this Statamic MCP server for the best development experience:

```bash
composer require laravel/boost --dev
```

**Why use both?**
- **Laravel Boost** and **Statamic MCP Server** run in parallel, complementing each other perfectly
- **Laravel Boost** provides Laravel-specific tools (Eloquent, database, debugging, Artisan commands)
- **Statamic MCP Server** provides Statamic-specific tools (blueprints, collections, entries, assets)
- Together, they give you complete AI-assisted development capabilities for your Statamic/Laravel application

With both installed, your AI assistant can:
- Use Laravel Boost for database queries, debugging, and Laravel framework operations
- Use Statamic MCP for content management, blueprint operations, and Statamic-specific tasks
- Seamlessly work across both the Laravel framework and Statamic CMS layers

### Development Installation

For local development or contributing:

```bash
# Clone the repository into your Statamic project's addons folder
mkdir -p addons/cboxdk
cd addons/cboxdk
git clone https://github.com/cboxdk/statamic-mcp.git

# Add to composer.json repositories section
"repositories": [
    {
        "type": "path",
        "url": "addons/cboxdk/statamic-mcp"
    }
]

# Install the addon
composer require cboxdk/statamic-mcp:@dev

# Install dependencies
cd addons/cboxdk/statamic-mcp
composer install
```

## ✨ Features

The MCP server provides 100+ tools organized into logical categories that mirror Statamic's architecture:

### 📋 Blueprint Tools
**Purpose**: Manage blueprint definitions and schema

- **`statamic.blueprints.list`** - List blueprints with optional details and filtering
- **`statamic.blueprints.get`** - Get specific blueprint with full field definitions
- **`statamic.blueprints.create`** - Create new blueprints from field definitions
- **`statamic.blueprints.update`** - Update existing blueprint fields and configuration
- **`statamic.blueprints.delete`** - Delete blueprints with safety checks
- **`statamic.blueprints.scan`** - Blueprint scanning with performance optimization
- **`statamic.blueprints.generate`** - Generate blueprints from templates and field definitions
- **`statamic.blueprints.types`** - Blueprint type analysis and TypeScript/PHP type generation

### 📚 Collection Tools
**Purpose**: Manage collection structures and configuration

- **`statamic.collections.list`** - List all collections with configuration details
- **`statamic.collections.get`** - Get specific collection with full configuration
- **`statamic.collections.create`** - Create new collections with blueprint associations
- **`statamic.collections.update`** - Update collection settings and structure
- **`statamic.collections.delete`** - Delete collections with safety checks

### 📚 Taxonomy Tools
**Purpose**: Manage taxonomies and their terms

- **`statamic.taxonomies.list`** - List all taxonomies with filtering and metadata
- **`statamic.taxonomies.get`** - Get specific taxonomy with detailed information
- **`statamic.taxonomies.create`** - Create new taxonomies with configuration
- **`statamic.taxonomies.update`** - Update taxonomy settings and associations
- **`statamic.taxonomies.delete`** - Delete taxonomies with safety checks
- **`statamic.taxonomies.analyze`** - Analyze taxonomy usage and term relationships
- **`statamic.taxonomies.terms`** - List and manage terms within taxonomies

### 🏗️ Structure Tools
**Purpose**: Manage structural configurations and scanning

- **`statamic.structures.fieldsets.scan`** - Fieldset analysis and parsing
- **`statamic.structures.fieldsets`** - Fieldset configuration management
- **`statamic.structures.navigations`** - Navigation structure management
- **`statamic.structures.forms`** - Form configuration management
- **`statamic.structures.globals`** - Global set configuration management
- **`statamic.structures.assets`** - Asset container configuration
- **`statamic.structures.groups`** - User group structure management
- **`statamic.structures.permissions`** - Permission structure analysis

### 📝 Entry Tools
**Purpose**: Manage entries across all collections

- **`statamic.entries.list`** - List entries with filtering, search, and pagination
- **`statamic.entries.get`** - Get specific entry with full data and relationships
- **`statamic.entries.create`** - Create new entries with validation and blueprint compliance
- **`statamic.entries.update`** - Update existing entries with merge options and validation
- **`statamic.entries.delete`** - Delete entries with safety checks and relationship validation
- **`statamic.entries.publish`** - Publish draft entries with validation
- **`statamic.entries.unpublish`** - Unpublish entries with safety checks

### 🏷️ Term Tools
**Purpose**: Manage taxonomy terms across all taxonomies

- **`statamic.terms.list`** - List terms with filtering, search, and pagination
- **`statamic.terms.get`** - Get specific term with full data and related entries
- **`statamic.terms.create`** - Create new terms with validation and slug conflict checking
- **`statamic.terms.update`** - Update existing terms with merge options and validation
- **`statamic.terms.delete`** - Delete terms with safety checks and dependency validation

### 🌐 Global Tools
**Purpose**: Manage global set values

- **`statamic.globals.list`** - List all global sets with metadata
- **`statamic.globals.get`** - Get specific global set with full data
- **`statamic.globals.update`** - Update global set values with validation

### 🧭 Navigation Tools
**Purpose**: Manage navigation structures

- **`statamic.navigation.list`** - List navigation trees with full structure

### 🌐 Sites Management Tools
**Purpose**: Multi-site Statamic configuration and management

- **`statamic.sites.list`** - List all configured sites with settings and status
- **`statamic.sites.create`** - Create new site configurations with validation
- **`statamic.sites.update`** - Update existing site configurations with backup options
- **`statamic.sites.delete`** - Delete sites with content analysis and cleanup options
- **`statamic.sites.switch`** - Switch default site with impact analysis
- **`statamic.sites.analyze`** - Analyze site configuration and detect potential issues

### 👥 User Management Tools
**Purpose**: Comprehensive user management with RBAC support

- **`statamic.users.list`** - List users with filtering, roles, and metadata
- **`statamic.users.get`** - Get specific user with detailed role and permission information
- **`statamic.users.create`** - Create new users with role assignment and validation
- **`statamic.users.update`** - Update users with granular role management
- **`statamic.users.delete`** - Delete users with content reassignment options
- **`statamic.users.analyze`** - Analyze user activity and permission usage patterns

### 🔐 Role & Permission Tools
**Purpose**: Role-based access control and security management

- **`statamic.roles.list`** - List all roles with permissions and user counts
- **`statamic.roles.get`** - Get specific role with detailed permission analysis
- **`statamic.roles.create`** - Create new roles with permission validation
- **`statamic.roles.update`** - Update roles with impact analysis on affected users
- **`statamic.roles.delete`** - Delete roles with user impact assessment
- **`statamic.permissions.list`** - List all available permissions with descriptions
- **`statamic.permissions.analyze`** - Analyze permission usage and security implications

### 🗂️ Other Content Tools
**Purpose**: Additional content management capabilities

- **`statamic.content.assets`** - Asset CRUD operations (coming soon)
- **`statamic.content.submissions`** - Form submission management (coming soon)

### 🏷️ Tag Tools
**Purpose**: Manage Statamic tags for both Antlers and Blade

- **`statamic.tags.list`** - Tag discovery, creation, and management for both Antlers and Blade

### 🔧 Modifier Tools
**Purpose**: Manage template modifiers

- **`statamic.modifiers.list`** - Modifier discovery, creation, and usage examples

### 🎛️ Field Type Tools
**Purpose**: Manage custom field types

- **`statamic.fieldtypes.list`** - Field type discovery, creation, and configuration options

### 🔍 Scope Tools
**Purpose**: Manage query scopes

- **`statamic.scopes.list`** - Query scope discovery and creation

### 🗂️ Filter Tools
**Purpose**: Manage collection filters

- **`statamic.filters.list`** - Filter discovery and creation

### ✅ Blueprint Validation Tools
**Purpose**: Blueprint integrity and field validation

- **`statamic.blueprints.validate`** - Validate blueprint structure and field configuration
- **`statamic.blueprints.dependencies`** - Analyze field dependencies and conditional logic
- **`statamic.blueprints.conflicts`** - Detect cross-blueprint field conflicts and naming issues

### ⚙️ Development Tools
**Purpose**: Enhanced developer experience and tooling

- **`statamic.development.templates`** - Template hints, validation, and optimization for Antlers/Blade
- **`statamic.development.addons`** - Addon development, analysis, and scaffolding
- **`statamic.development.addon.discovery`** - Addon discovery and recommendations
- **`statamic.development.types`** - TypeScript/PHP type generation from blueprints
- **`statamic.development.console`** - Artisan command execution and management
- **`statamic.development.antlers.validate`** - Antlers template validation and syntax checking
- **`statamic.development.blade.hints`** - Blade template hints and suggestions
- **`statamic.development.blade.lint`** - Blade template linting and best practices
- **`statamic.development.widgets`** - Widget development and management
- **`statamic.development.performance.analyze`** - Comprehensive template performance analysis with N+1 detection
- **`statamic.development.templates.unused`** - Detect unused templates, partials, and layouts
- **`statamic.development.templates.variables`** - Extract variables from templates with type analysis
- **`statamic.development.templates.optimize`** - Suggest specific template optimizations with examples

### 🔧 System Tools
**Purpose**: System management and operations

- **`statamic.system.info`** - Comprehensive system analysis and health checks
- **`statamic.system.cache`** - Advanced cache management with selective clearing and warming
- **`statamic.system.docs`** - Statamic documentation search with AI relevance scoring
- **`statamic.system.license`** - License management (solo/pro, addon licensing, key configuration)
- **`statamic.system.preferences`** - Multi-level preferences management (global/role/user)
- **`statamic.system.stache`** - Advanced Stache cache operations (clear/warm/analyze/optimize)
- **`statamic.system.search.index`** - Search index performance analysis and optimization
- **`statamic.system.discover`** - Dynamically discover all available MCP tools with examples
- **`statamic.system.schema`** - Get detailed schema information for specific tools
- **`statamic.system.health`** - Comprehensive system health check with security analysis
- **`statamic.system.monitor`** - Performance analysis with sample operations and bottleneck detection

## 🏗️ Architecture & Design

### Clean MCP Tool Architecture
The addon follows a **single-purpose tool pattern** where each tool performs exactly ONE action:
- **No action conditionals**: Each tool has a focused responsibility
- **Predictable schemas**: Clear input/output contracts
- **Better performance**: Reduced token overhead for AI assistants
- **Easier testing**: Isolated, testable components

### Security & Reliability
- **Path traversal protection**: All file operations validated against allowed directories
- **Input sanitization**: Sensitive data redacted from logs
- **Structured error handling**: Standardized error codes and responses
- **Type safety**: PHPStan Level 8 compliance with strict typing

### Developer-Focused Design
- **Local development first**: Optimized for development workflows
- **Smart caching**: Expensive operations cached with dependency tracking
- **Comprehensive logging**: Structured logs with correlation IDs for debugging
- **No unnecessary complexity**: No rate limiting or emergency logging in dev tools

## 🎯 New Features & Performance

### ⚡ Automatic Cache Purging
All structural and content changes automatically clear relevant caches:
- **Blueprint/fieldset changes**: Clears stache, static, views
- **Content operations**: Clears stache, static caches  
- **Structure changes**: Comprehensive cache clearing
- **Transparent reporting**: All responses include cache status

### 📊 Performance Optimizations
- **Pagination support**: Use `limit` and `filter` parameters for large datasets
- **Field filtering**: `include_fields: false` for blueprint scanning performance
- **Response limits**: Automatic limits to prevent token overflow (< 25,000 tokens)
- **Smart defaults**: Optimized for AI assistant token limits
- **Smart caching**: Discovery operations cached with file modification tracking

## 🤖 AI Assistant Setup


```bash
# Run the installation command to set up MCP
php artisan mcp:statamic:install
```

See [docs/AI_ASSISTANT_SETUP.md](docs/AI_ASSISTANT_SETUP.md) for more details or manual setup.

## 💡 Example Usage with AI

Once configured, you can ask your AI assistant:

```
"What version of Statamic is installed and is it Pro or Solo?"

"Show me all my blueprint structures and generate TypeScript types"

"List all global sets and their current values across all sites"

"Create a new global set for company contact information with phone, email, and address fields"

"Update the footer global values for the Danish site"

"What modifiers and filters are available in my project?"

"Analyze this Antlers template for performance issues and security vulnerabilities"

"Lint this Blade template and detect N+1 query problems"

"Validate this template against my blueprint and check for edge cases"

"Create a new blog entry with proper field validation"

"Search for documentation about collections and how they work"

"Check my templates for XSS vulnerabilities and recursive partial issues"

"What global sets exist and what's their blueprint structure?"

"Clear all Statamic caches and show me the status"

"Analyze template performance and suggest optimizations"

"Check for missing pagination in large collection loops"

"Find templates with excessive complexity and suggest refactoring"

"Show me user preferences and configure global settings"
```

## 🎯 Key Capabilities

### System Intelligence & Management
- **Installation Analysis**: Version, edition (Pro/Solo), licensing status, multi-site configuration
- **Storage Detection**: File-based, database (Runway), or mixed storage patterns  
- **Content Extraction**: Dynamic analysis of modifiers, globals, taxonomies, users, permissions
- **Cache Management**: Clear, warm, and monitor all Statamic caches (Stache, static, images, views)

### Content Operations
- **CRUD Operations**: Create, edit, delete, reorder entries, taxonomy terms, navigation items
- **Content Discovery**: Extract all content types with filtering and metadata
- **Bulk Operations**: Mass content management and organization
- **Data Integrity**: Safe operations with proper validation and error handling

### Blueprint Intelligence  
- **Complete Analysis**: Blueprint and fieldset scanning with relationship mapping
- **Type Generation**: TypeScript, PHP classes, JSON Schema from blueprints
- **Field Categories**: 25+ supported field types with validation patterns
- **Dynamic Discovery**: On-demand field type and configuration analysis

### Documentation Intelligence
- **Dynamic Search**: Live content from statamic.dev with sitemap parsing
- **Relevance Scoring**: Intelligent content ranking and suggestions
- **Addon Coverage**: Third-party documentation and community resources
- **Comprehensive Coverage**: All modifiers, tags, and constantly updated content

### Template Development
- **Language-Aware Hints**: Context-appropriate suggestions for Antlers vs Blade
- **Syntax Validation**: Blueprint-driven template validation with error reporting
- **Best Practices**: Anti-pattern detection with auto-fix suggestions
- **Template Separation**: Clear guidance on when to use Antlers vs Blade

### Code Quality & Security
- **Policy Enforcement**: Configurable linting rules for both Antlers and Blade
- **Security Detection**: Template vulnerability scanning and prevention
- **Accessibility**: Compliance checks and automated improvements
- **Performance**: Template optimization suggestions and cache-aware development

## 📁 Project Structure

```
statamic-mcp/
├── src/Mcp/Tools/                      # 50+ specialized MCP tools
│   ├── Blueprints/                     # Blueprint management and analysis
│   │   ├── ListBlueprintsTool.php
│   │   ├── GetBlueprintTool.php
│   │   ├── CreateBlueprintTool.php
│   │   ├── UpdateBlueprintTool.php
│   │   ├── DeleteBlueprintTool.php
│   │   ├── ScanBlueprintsTool.php
│   │   ├── GenerateBlueprintTool.php
│   │   ├── TypesBlueprintTool.php
│   │   ├── ValidateBlueprintTool.php
│   │   ├── CheckFieldDependenciesTool.php
│   │   └── DetectFieldConflictsTool.php
│   ├── Collections/                    # Collection management
│   │   ├── ListCollectionsTool.php
│   │   ├── GetCollectionTool.php
│   │   ├── CreateCollectionTool.php
│   │   ├── UpdateCollectionTool.php
│   │   └── DeleteCollectionTool.php
│   ├── Taxonomies/                     # Taxonomy management
│   │   ├── ListTaxonomyTool.php
│   │   ├── GetTaxonomyTool.php
│   │   ├── CreateTaxonomyTool.php
│   │   ├── UpdateTaxonomyTool.php
│   │   ├── DeleteTaxonomyTool.php
│   │   ├── AnalyzeTaxonomyTool.php
│   │   └── ListTermsTool.php
│   ├── Entries/                        # Entry management
│   │   ├── ListEntresTool.php
│   │   ├── GetEntryTool.php
│   │   ├── CreateEntryTool.php
│   │   ├── UpdateEntryTool.php
│   │   ├── DeleteEntryTool.php
│   │   ├── PublishEntryTool.php
│   │   └── UnpublishEntryTool.php
│   ├── Terms/                          # Term management
│   │   ├── ListTermsTool.php
│   │   ├── GetTermTool.php
│   │   ├── CreateTermTool.php
│   │   ├── UpdateTermTool.php
│   │   └── DeleteTermTool.php
│   ├── Globals/                        # Global sets and values management
│   │   ├── ListGlobalSetsTool.php
│   │   ├── GetGlobalSetTool.php
│   │   ├── CreateGlobalSetTool.php
│   │   ├── DeleteGlobalSetTool.php
│   │   ├── ListGlobalValuesTool.php
│   │   ├── GetGlobalValuesTool.php
│   │   └── UpdateGlobalValuesTool.php
│   ├── Navigation/                     # Navigation management
│   │   └── ListNavigationTool.php
│   ├── Content/                        # Other content operations
│   │   ├── AssetsContentTool.php
│   │   ├── SubmissionsContentTool.php
│   │   └── UsersContentTool.php
│   ├── Tags/                           # Tag management
│   │   └── ListTagsTool.php
│   ├── Modifiers/                      # Modifier management
│   │   └── ListModifiersTool.php
│   ├── FieldTypes/                     # Field type management
│   │   └── ListFieldTypesTool.php
│   ├── Scopes/                         # Scope management
│   │   └── ListScopesTool.php
│   ├── Filters/                        # Filter management
│   │   └── ListFiltersTool.php
│   ├── Sites/                           # Multi-site management
│   │   ├── ListSitesTool.php
│   │   ├── CreateSiteTool.php
│   │   ├── UpdateSiteTool.php
│   │   ├── DeleteSiteTool.php
│   │   ├── SwitchSiteTool.php
│   │   └── AnalyzeSitesTool.php
│   ├── Users/                           # User management and RBAC
│   │   ├── ListUsersTool.php
│   │   ├── GetUserTool.php
│   │   ├── CreateUserTool.php
│   │   ├── UpdateUserTool.php
│   │   ├── DeleteUserTool.php
│   │   └── AnalyzeUsersTool.php
│   ├── Roles/                           # Role management
│   │   ├── ListRolesTool.php
│   │   ├── GetRoleTool.php
│   │   ├── CreateRoleTool.php
│   │   ├── UpdateRoleTool.php
│   │   ├── DeleteRoleTool.php
│   │   ├── ListPermissionsTool.php
│   │   └── AnalyzePermissionsTool.php
│   ├── Development/                    # Advanced developer tools
│   │   ├── TemplatesDevelopmentTool.php
│   │   ├── AddonsDevelopmentTool.php
│   │   ├── AddonDiscoveryTool.php
│   │   ├── TypesDevelopmentTool.php
│   │   ├── ConsoleDevelopmentTool.php
│   │   ├── AntlersValidateTool.php
│   │   ├── BladeLintTool.php
│   │   ├── OptimizedTemplateAnalyzer.php  # Advanced template analysis
│   │   ├── AnalyzeTemplatePerformanceTool.php
│   │   ├── DetectUnusedTemplatesTool.php
│   │   ├── ExtractTemplateVariablesTool.php
│   │   ├── SuggestTemplateOptimizationsTool.php
│   │   └── WidgetsDevelopmentTool.php
│   ├── Structures/                     # Structure management
│   │   ├── FieldsetsScanStructuresTool.php
│   │   ├── FieldsetsStructureTool.php
│   │   ├── NavigationsStructureTool.php
│   │   ├── FormsStructureTool.php
│   │   ├── GlobalsStructureTool.php
│   │   ├── AssetsStructureTool.php
│   │   ├── GroupsStructureTool.php
│   │   └── PermissionsStructureTool.php
│   └── System/                         # System operations
│       ├── InfoSystemTool.php
│       ├── CacheSystemTool.php
│       ├── DocsSystemTool.php
│       ├── GetLicenseStatusTool.php
│       ├── VerifyLicenseTool.php
│       ├── PreferencesManagementTool.php
│       ├── StacheManagementTool.php
│       ├── SearchIndexAnalyzerTool.php
│       ├── SitesTool.php
│       ├── DiscoverToolsTool.php
│       ├── GetToolSchemaTool.php
│       ├── SystemHealthCheckTool.php
│       └── PerformanceMonitorTool.php
├── tests/                              # Comprehensive test suite  
├── docs/                               # Detailed documentation
└── config/statamic_mcp.php            # Configuration options
```

## 📚 Documentation

### Tool Discovery
Use the built-in discovery tools to explore available capabilities:

```bash
# Discover all available tools with their schemas
"Use the statamic.system.tools.discover tool to show me all available tools"

# Get detailed schema for a specific tool
"Show me the schema for statamic.entries.create"

# Find tools by domain
"What blueprint management tools are available?"
```

### Additional Documentation
- **Installation Guide**: See Installation section above
- **AI Assistant Setup**: See AI Assistant Setup section
- **Tool Examples**: Use discovery tools for live examples

## ⚙️ Configuration

Publish and customize the configuration:

```bash
php artisan vendor:publish --tag=statamic-mcp-config
```

Configure blueprint paths, linting rules, cache settings, and more in `config/statamic_mcp.php`.

## 🧪 Development & Testing

### Running Tests
```bash
# Run all tests with Pest
./vendor/bin/pest
composer test

# Run with coverage report
./vendor/bin/pest --coverage
composer test:coverage

# Development watch mode
./vendor/bin/pest --watch

# Run specific test file
./vendor/bin/pest tests/BlueprintsScanToolTest.php
```

### Code Quality
```bash
# Format code with Laravel Pint
./vendor/bin/pint
composer pint

# Check formatting without fixing
./vendor/bin/pint --test
composer pint:test

# Run PHPStan Level 8 analysis
./vendor/bin/phpstan analyse
composer stan

# Run complete quality check (format + analysis + tests)
composer quality
```

### Quality Standards
This project maintains the highest code quality standards:
- **PHPStan Level 8**: Strict type checking with zero tolerance for errors
- **Laravel Pint**: Consistent code formatting following Laravel conventions
- **Type Safety**: All methods have explicit parameter and return types
- **Test Coverage**: Comprehensive test suite with 92+ passing tests
- **Strict Types**: All PHP files declare `strict_types=1`

## 🔧 Troubleshooting

### Common Issues

**MCP server not connecting in Claude:**
- Ensure absolute paths in config file
- Check `APP_ENV` is set to `local`
- Verify PHP path: `which php`
- Test manually: `php artisan mcp:serve statamic`

**Tools not appearing:**
- Clear Laravel cache: `php artisan cache:clear`
- Check service provider registration
- Verify `laravel/mcp` is installed: `composer show laravel/mcp`

**PHPStan errors:**
- Run `composer update` to ensure latest dependencies
- Check PHP version: minimum 8.2 required
- Clear PHPStan cache: `./vendor/bin/phpstan clear-result-cache`

**Test failures:**
- Ensure Statamic is properly installed
- Check test database configuration
- Run `composer dump-autoload`

## 🤝 Contributing

1. Fork the repository
2. Install: `composer install`
3. Test: `./vendor/bin/pest`
4. Ensure quality checks pass: `composer quality`
5. Submit pull request

## 📄 License

MIT License

---

**Enhanced Statamic development with AI assistance** 🚀
