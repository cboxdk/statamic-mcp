<?php

namespace Cboxdk\StatamicMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class InstallCommand extends Command
{
    protected $signature = 'mcp:statamic:install {--force : Overwrite existing configuration}';

    protected $description = 'Install and configure Statamic MCP Server for AI assistants';

    public function handle()
    {
        $this->info('🚀 Installing Statamic MCP Server...');
        $this->newLine();

        // Publish configuration
        $this->publishConfiguration();

        // Detect and configure AI assistants
        $this->configureAiAssistants();

        // Create MCP guidelines
        $this->createMcpGuidelines();

        // Show completion message
        $this->showCompletionMessage();

        return 0;
    }

    protected function publishConfiguration()
    {
        $this->info('📋 Publishing configuration...');

        $configExists = File::exists(config_path('statamic_mcp.php'));

        if ($configExists && ! $this->option('force')) {
            if (! $this->confirm('Configuration already exists. Overwrite?')) {
                $this->info('⏭️  Skipping configuration publish.');

                return;
            }
        }

        $this->call('vendor:publish', [
            '--tag' => 'statamic-mcp-config',
            '--force' => $this->option('force'),
        ]);

        $this->info('✅ Configuration published successfully.');
        $this->newLine();
    }

    protected function configureAiAssistants()
    {
        $this->info('🤖 Configuring AI assistants...');

        $projectPath = base_path();
        $assistants = [
            'Claude Code' => $this->configureClaudeCode($projectPath),
            'Cursor' => $this->configureCursor($projectPath),
            'Cline' => $this->configureCline($projectPath),
        ];

        foreach ($assistants as $name => $configured) {
            if ($configured) {
                $this->info("✅ {$name} configuration created/updated");
            } else {
                $this->warn("⏭️  {$name} not configured (not detected or skipped)");
            }
        }

        $this->newLine();
    }

    protected function configureClaudeCode(string $projectPath): bool
    {
        // First, detect if Claude CLI is available (like Laravel Boost does)
        $claudeCliAvailable = $this->detectClaudeCli();

        if ($claudeCliAvailable) {
            // Use the modern Claude MCP approach with .mcp.json
            return $this->configureClaudeCodeModern($projectPath);
        }

        // Fallback to desktop config for older installations
        return $this->configureClaudeCodeLegacy($projectPath);
    }

    protected function detectClaudeCli(): bool
    {
        try {
            // Check for claude command (handles aliases too)
            $result = Process::run(['bash', '-c', 'command -v claude']);
            if ($result->successful()) {
                return true;
            }

            // Also check for Claude Code JetBrains plugin
            $jetbrainsPlugins = glob($this->getHomeDirectory() . '/Library/Application Support/JetBrains/*/plugins/claude-code-jetbrains-plugin');
            if (! empty($jetbrainsPlugins)) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function configureClaudeCodeModern(string $projectPath): bool
    {
        // Get the Laravel project root (not the addon directory)
        $laravelRoot = dirname(dirname($projectPath)); // Go up from addons/cboxdk/statamic-mcp to project root
        $mcpConfigPath = $laravelRoot . '/.mcp.json';

        $config = [];
        if (File::exists($mcpConfigPath)) {
            $config = json_decode(File::get($mcpConfigPath), true) ?? [];

            if (isset($config['mcpServers']['statamic']) && ! $this->option('force')) {
                if (! $this->confirm('Claude Code MCP configuration exists. Update it?')) {
                    return false;
                }
            }
        }

        $config['mcpServers']['statamic'] = [
            'command' => 'php',
            'args' => [
                'artisan',
                'mcp:start',
                'statamic',
            ],
            'env' => [
                'APP_ENV' => 'local',
            ],
        ];

        File::put($mcpConfigPath, json_encode($config, JSON_PRETTY_PRINT));

        // Also try to register via claude mcp add command
        $this->tryRegisterMcpCommand($laravelRoot);

        return true;
    }

    protected function configureClaudeCodeLegacy(string $projectPath): bool
    {
        // Fallback to desktop config for older Claude installations
        $homeDir = $this->getHomeDirectory();
        $locations = [
            $homeDir . '/Library/Application Support/Claude/claude_desktop_config.json', // macOS
            $homeDir . '/.config/claude/claude_desktop_config.json', // Linux
            (isset($_SERVER['APPDATA']) ? $_SERVER['APPDATA'] . '/Claude/claude_desktop_config.json' : null), // Windows
        ];

        $configPath = null;
        foreach ($locations as $location) {
            if ($location && File::exists(dirname($location))) {
                $configPath = $location;
                break;
            }
        }

        if (! $configPath) {
            $this->warn('Claude Code configuration directory not found.');

            return false;
        }

        if (File::exists($configPath) && ! $this->option('force')) {
            if (! $this->confirm("Claude Code config exists at {$configPath}. Update it?")) {
                return false;
            }
        }

        $config = [];
        if (File::exists($configPath)) {
            $config = json_decode(File::get($configPath), true) ?? [];
        }

        $config['mcpServers']['statamic'] = [
            'command' => 'php',
            'args' => [
                $projectPath . '/artisan',
                'mcp:start',
                'statamic',
            ],
            'env' => [
                'APP_ENV' => 'local',
            ],
        ];

        File::put($configPath, json_encode($config, JSON_PRETTY_PRINT));

        return true;
    }

    protected function configureCursor(string $projectPath): bool
    {
        $cursorrules = base_path('.cursorrules');

        if (File::exists($cursorrules) && ! $this->option('force')) {
            if (! $this->confirm('.cursorrules file exists. Update it?')) {
                return false;
            }
        }

        $content = $this->getCursorRulesContent($projectPath);
        File::put($cursorrules, $content);

        return true;
    }

    protected function configureCline(string $projectPath): bool
    {
        // Check if VS Code settings exist
        $vscodeDir = base_path('.vscode');
        $settingsPath = $vscodeDir . '/settings.json';

        if (! File::exists($vscodeDir)) {
            File::makeDirectory($vscodeDir);
        }

        $settings = [];
        if (File::exists($settingsPath)) {
            $settings = json_decode(File::get($settingsPath), true) ?? [];

            if (isset($settings['cline.mcpServers']) && ! $this->option('force')) {
                if (! $this->confirm('Cline MCP configuration exists in VS Code settings. Update it?')) {
                    return false;
                }
            }
        }

        $settings['cline.mcpServers']['statamic'] = [
            'command' => 'php',
            'args' => [
                $projectPath . '/artisan',
                'mcp:serve',
                'statamic',
            ],
            'env' => [
                'APP_ENV' => 'local',
                'PATH' => '/usr/local/bin:/usr/bin:/bin',
            ],
            'cwd' => $projectPath,
        ];

        File::put($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));

        return true;
    }

    protected function createMcpGuidelines()
    {
        $this->info('📝 Creating MCP guidelines...');

        $aiDir = base_path('.ai');
        if (! File::exists($aiDir)) {
            File::makeDirectory($aiDir);
        }

        $guidelinesDir = $aiDir . '/guidelines';
        if (! File::exists($guidelinesDir)) {
            File::makeDirectory($guidelinesDir);
        }

        $guidelinesPath = $guidelinesDir . '/statamic-mcp.md';

        if (File::exists($guidelinesPath) && ! $this->option('force')) {
            if (! $this->confirm('MCP guidelines already exist. Overwrite?')) {
                $this->info('⏭️  Skipping guidelines creation.');

                return;
            }
        }

        $guidelines = $this->getStatamicMcpGuidelines();
        File::put($guidelinesPath, $guidelines);

        $this->info('✅ MCP guidelines created successfully.');
        $this->newLine();
    }

    protected function showCompletionMessage()
    {
        $this->info('🎉 Statamic MCP Server installation complete!');
        $this->newLine();

        $this->info('📚 What was configured:');
        $this->line('  • Published MCP server configuration');
        $this->line('  • Created AI assistant configurations where possible');
        $this->line('  • Added MCP guidelines for better AI understanding');
        $this->newLine();

        $this->info('🚀 Next Steps:');
        $this->line('  1. Restart your AI assistant (Claude Code, Cursor, etc.)');
        $this->line('  2. Test the MCP connection: php artisan mcp:start statamic');
        $this->line('  3. Verify MCP registration: claude mcp list');
        $this->line('  4. Ask your AI: "What Statamic MCP tools are available?"');
        $this->newLine();

        $this->info('📖 Available MCP Tools (140+ tools):');
        $this->line('  • statamic.system.info - System information & analysis');
        $this->line('  • statamic.blueprints.list - List all blueprints with details');
        $this->line('  • statamic.entries.list - List entries with pagination');
        $this->line('  • statamic.collections.list - List all collections');
        $this->line('  • statamic.globals.values.list - List global values');
        $this->line('  • statamic.users.list - List users with roles');
        $this->line('  • statamic.system.cache - Advanced cache management');
        $this->line('  • statamic.development.templates.unused - Find unused templates');
        $this->line('  • statamic.development.performance.analyze - Template performance analysis');
        $this->line('  • statamic.system.tools.discover - Discover all available tools');
        $this->line('  • And 130+ more tools across 15 categories...');
        $this->newLine();

        $this->info('📋 Manual Configuration:');
        $this->line('  • See docs/AI_ASSISTANT_SETUP.md for manual setup');
        $this->line('  • GitHub Copilot users: Check .github/copilot-instructions.md');
        $this->newLine();

        $this->info('Happy coding with AI assistance! 🤖✨');
    }

    protected function getCursorRulesContent(string $projectPath): string
    {
        return <<<MARKDOWN
# Statamic MCP Server Integration

This project uses Statamic MCP Server for enhanced AI-assisted development.

## MCP Server Configuration
- Command: php artisan mcp:serve statamic
- Project Path: {$projectPath}
- Tools Available: 11 MCP tools for Statamic development

## Available MCP Tools (140+ tools)

### System & Discovery Tools
- **statamic.system.info**: Get Statamic installation information
- **statamic.system.cache**: Advanced cache management
- **statamic.system.health**: System health check
- **statamic.system.tools.discover**: Discover all available tools dynamically

### Blueprint Tools
- **statamic.blueprints.list**: List all blueprints
- **statamic.blueprints.get**: Get specific blueprint details
- **statamic.blueprints.create**: Create new blueprints
- **statamic.blueprints.scan**: Analyze blueprint structures
- **statamic.blueprints.validate**: Validate blueprint configuration

### Entry & Content Tools  
- **statamic.entries.list**: List entries with pagination
- **statamic.entries.get**: Get specific entry
- **statamic.entries.create**: Create new entries
- **statamic.entries.update**: Update existing entries
- **statamic.entries.delete**: Delete entries safely

### Global Tools
- **statamic.globals.values.list**: List global values
- **statamic.globals.values.get**: Get specific global values
- **statamic.globals.values.update**: Update global values
- **statamic.globals.sets.list**: List global sets

### Development Tools
- **statamic.development.templates.unused**: Find unused templates
- **statamic.development.performance.analyze**: Analyze template performance
- **statamic.development.templates.optimize**: Suggest optimizations
- **statamic.development.templates.variables**: Extract template variables

## Development Guidelines

### Statamic Best Practices
1. **Use MCP tools to understand the project**:
   - Start with `statamic.system.info` to understand the installation
   - Use `statamic.system.tools.discover` to see all available tools
   - Use `statamic.blueprints.list` to see blueprint structures
   - Use `statamic.entries.list` with pagination for large datasets

2. **Blueprint & Content Management**:
   - Use `statamic.blueprints.create` for new blueprints
   - Use `statamic.entries.create` to create content
   - Use `statamic.globals.values.update` for global settings
   - All structural changes automatically clear relevant caches

3. **Template Development**:
   - Use `statamic.development.templates.unused` to find unused templates
   - Use `statamic.development.performance.analyze` for performance issues
   - Use `statamic.development.templates.optimize` for suggestions
   - Avoid direct facade calls in views: prefer Statamic tags

4. **Performance & Caching**:
   - MCP tools automatically purge caches after structural changes
   - Use pagination parameters to avoid large responses (limit, filter)
   - Blueprint scanning supports `include_fields: false` for performance

### Code Quality Rules
- No inline PHP in Blade templates
- No direct facade calls (Statamic, DB, Http, Cache) in views
- Always include alt text for images
- Use descriptive link text for accessibility
- Prefer Statamic components and tags

### Field Type Categories
- **Text**: text, textarea, markdown, code
- **Rich Content**: bard, redactor
- **Media**: assets, video
- **Relationships**: entries, taxonomy, users, collections
- **Structured Data**: replicator, grid, group, yaml, array
- **Special**: date, color, toggle, select, range

When suggesting Statamic implementations, always reference MCP tools and follow these patterns.
MARKDOWN;
    }

    protected function getStatamicMcpGuidelines(): string
    {
        return <<<'MARKDOWN'
# Statamic MCP Guidelines

This file provides AI assistants with comprehensive understanding of the Statamic MCP Server capabilities and best practices.

## MCP Server Overview

The Statamic MCP Server provides 140+ specialized tools for enhanced Statamic development:

### Key Tool Categories

**Blueprints** (10+ tools): List, get, create, update, delete, validate, scan
**Collections** (6+ tools): List, get, create, update, delete, reorder
**Entries** (15+ tools): CRUD operations, publishing, relationships, batch operations
**Taxonomies** (7+ tools): List, get, create, update, delete, analyze, terms
**Globals** (9+ tools): Sets and values management across sites
**Users & Roles** (11+ tools): User management, roles, permissions
**Assets** (8+ tools): File management, upload, move, rename, delete
**Forms** (10+ tools): Form configuration, submissions, export
**Sites** (6+ tools): Multi-site configuration and management
**Development** (15+ tools): Template analysis, performance, optimization
**System** (13+ tools): Cache, health, monitoring, discovery

Use `statamic.system.tools.discover` to explore all available tools with their schemas.

## Usage Patterns

### Discovery Phase
Always start development sessions with:
1. `statamic.system.info` - Understand the installation
2. `statamic.system.tools.discover` - See all available tools
3. `statamic.blueprints.list` - Map content structure
4. `statamic.collections.list` - See collection configuration

### Development Phase
For template work:
- Use `statamic.development.templates.unused` to find unused templates
- Use `statamic.development.performance.analyze` for performance analysis
- Use `statamic.development.templates.optimize` for optimization suggestions

For content management:
- Use `statamic.entries.list` to browse content
- Use `statamic.globals.values.list` for global settings

### Content Architecture
Create structures with appropriate tools:

**Creating a Collection:**
Use `statamic.collections.create` with proper configuration

**Creating Blueprints:**
Use `statamic.blueprints.create` with field definitions

**Managing Entries:**
Use `statamic.entries.create`, `statamic.entries.update`, `statamic.entries.delete`

**Global Settings:**
Use `statamic.globals.sets.create` for new global sets
Use `statamic.globals.values.update` for content

### Code Generation & Analysis
- Generate types with `statamic.blueprints.types`
- Analyze performance with `statamic.development.performance.analyze`
- Find unused code with `statamic.development.templates.unused`
- Extract variables with `statamic.development.templates.variables`

## Statamic Development Best Practices

### Primary Templating Language
Always consider the project's primary templating language when making suggestions:
- **Antlers-first projects**: Prefer Antlers syntax, use Antlers tags and variables
- **Blade-first projects**: Prefer Blade components, use Statamic Blade tags

### Template Language-Specific Patterns

#### Antlers Templates (Primary: Antlers)
1. **Use Antlers syntax**: `{{ title }}`, `{{ collection:articles }}`
2. **Field relationships**: `{{ author:name }}`, `{{ categories }}{{ title }}{{ /categories }}`
3. **Conditional logic**: `{{ if featured }}...{{ /if }}`
4. **Loops**: `{{ collection:blog }}{{ title }}{{ /collection:blog }}`
5. **Modifiers**: `{{ content | markdown }}`, `{{ date | format:Y-m-d }}`

#### Blade Templates (Primary: Blade)
1. **Use Statamic Blade tags**: `<s:collection>`, `<s:form:create>`
2. **Blade directives**: `@if`, `@foreach`, `@include`
3. **Components**: `<x-card>`, custom Blade components
4. **Avoid facades in views**: Use tags instead of `Entry::all()`
5. **Field access**: `{{ $entry->title }}`, `{{ $entry->author->name }}`

### Mixed Approach
- **Antlers for content templates**: Simple content display, loops, conditionals
- **Blade for complex logic**: Components, layouts, complex data processing
- **Never mix syntaxes in same template**: Choose one approach per template

### Content Architecture
1. **Blueprint-driven**: Design content structure first
2. **Relationship mapping**: Use entries, taxonomy, users appropriately
3. **Field type selection**: Match field types to content needs
4. **Validation rules**: Include appropriate validation

### Code Quality
1. **No inline PHP** in templates (both Antlers and Blade)
2. **No direct facades** in views (use Statamic tags)
3. **Proper error handling** for missing content
4. **Security considerations** for user input

## Field Type Reference

### Text Fields
- `text` - Single line text
- `textarea` - Multi-line text
- `markdown` - Markdown with preview
- `code` - Syntax highlighted code

### Rich Content
- `bard` - Rich editor with custom sets
- `redactor` - Alternative rich editor

### Media
- `assets` - File/image management
- `video` - Video embedding

### Relationships
- `entries` - Link to other entries
- `taxonomy` - Link to taxonomy terms
- `users` - Link to user accounts
- `collections` - Reference collections

### Structured Data
- `replicator` - Flexible content blocks
- `grid` - Tabular data
- `group` - Field grouping
- `yaml` - Raw YAML data

## AI Assistant Integration

When working with Statamic projects:

1. **Always use MCP tools** to understand the current state
2. **Reference actual blueprints** rather than assuming structure
3. **Validate suggestions** against real configuration
4. **Provide working examples** based on actual field types
5. **Consider the full context** (addons, configuration, relationships)

## Error Handling

All MCP tools provide consistent error responses. When tools return errors:
- Check field/blueprint existence with `statamic.blueprints.scan`
- Verify syntax with validation tools
- Search documentation for proper usage patterns
- Consider alternative approaches

This guideline ensures AI assistants provide accurate, contextual, and practical Statamic development assistance.
MARKDOWN;
    }

    protected function tryRegisterMcpCommand(string $laravelRoot): void
    {
        // Try to register with Claude MCP command if available
        try {
            // Check if claude command is available
            $claudeCheck = Process::run(['bash', '-c', 'command -v claude']);
            if ($claudeCheck->failed()) {
                return; // Claude CLI not available
            }

            // Try to add MCP server via command line (run from Laravel root)
            $mcpCommand = [
                'claude', 'mcp', 'add',
                'statamic',
                'php artisan mcp:start statamic',
                '--scope', 'project',
            ];

            $result = Process::timeout(30)->path($laravelRoot)->run($mcpCommand);

            if ($result->successful()) {
                $this->info('✅ MCP server registered with Claude CLI');
            }
        } catch (\Exception $e) {
            // Ignore errors - .mcp.json was already created
        }
    }

    protected function getHomeDirectory(): string
    {
        return $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';
    }
}
