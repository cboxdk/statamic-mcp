<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;

class InstallCommand extends Command
{
    protected $signature = 'mcp:statamic:install {--force : Overwrite existing configuration} {--debug : Show debug information}';

    protected $description = 'Install and configure Statamic MCP Server for AI assistants';

    public function handle(): int
    {
        $this->info('🚀 Installing Statamic MCP Server v2.0...');
        $this->newLine();

        // Publish configuration
        $this->publishConfiguration();

        // Run migrations
        $this->runMigrations();

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

        $configExists = File::exists(config_path('statamic/mcp.php'));

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

    protected function runMigrations()
    {
        $this->info('🗄️  Running migrations...');

        $this->call('migrate', [
            '--force' => $this->option('force'),
        ]);

        $this->info('✅ Migrations completed successfully.');
        $this->newLine();
    }

    protected function configureAiAssistants()
    {
        $this->info('🤖 Detecting AI assistants...');

        // Detect available AI agents/environments
        $availableAgents = $this->detectAiAgents();

        if (empty($availableAgents)) {
            $this->warn('No AI agents detected. You can manually configure them later.');
            $this->newLine();

            return;
        }

        $this->info('Found ' . count($availableAgents) . ' AI agent(s): ' . implode(', ', array_keys($availableAgents)));
        $this->newLine();

        // Ask user which agents to configure
        $selectedAgents = $this->selectTargetAgents($availableAgents);

        if (empty($selectedAgents)) {
            $this->info('No agents selected for configuration.');
            $this->newLine();

            return;
        }

        $this->info('🔧 Configuring selected AI assistants...');

        $projectPath = base_path();
        $configuredCount = 0;

        foreach ($selectedAgents as $agent) {
            $configured = false;

            switch ($agent) {
                case 'Claude Code':
                    $configured = $this->configureClaudeCode($projectPath);
                    break;
                case 'Cursor':
                    $configured = $this->configureCursor($projectPath);
                    break;
                case 'Cline':
                    $configured = $this->configureCline($projectPath);
                    break;
            }

            if ($configured) {
                $this->info("✅ {$agent} configuration created/updated");
                $configuredCount++;
            } else {
                $this->warn("⏭️  {$agent} configuration skipped");
            }
        }

        $this->info("Configured {$configuredCount} of " . count($selectedAgents) . ' selected agents.');
        $this->newLine();
    }

    protected function detectAiAgents(): array
    {
        $agents = [];

        // Detect Claude Code
        if ($this->detectClaudeCode()) {
            $agents['Claude Code'] = [
                'type' => 'claude-code',
                'detected' => true,
                'description' => 'Claude Code CLI or desktop app detected',
            ];
        }

        // Detect Cursor
        if ($this->detectCursor()) {
            $agents['Cursor'] = [
                'type' => 'cursor',
                'detected' => true,
                'description' => 'Cursor IDE detected',
            ];
        }

        // Detect Cline (VS Code extension)
        if ($this->detectCline()) {
            $agents['Cline'] = [
                'type' => 'cline',
                'detected' => true,
                'description' => 'VS Code with Cline extension detected',
            ];
        }

        return $agents;
    }

    protected function selectTargetAgents(array $availableAgents): array
    {
        if ($this->option('force')) {
            // If force option is used, configure all detected agents
            return array_keys($availableAgents);
        }

        // Use Laravel Prompts multiselect for interactive selection
        $choices = [];
        foreach ($availableAgents as $name => $info) {
            $choices[$name] = $name . ' - ' . $info['description'];
        }

        $selected = multiselect(
            label: 'Which AI assistants would you like to configure?',
            options: $choices,
            default: array_keys($choices), // Auto-select all detected agents
            required: false,
            hint: 'Use space to select/deselect, enter to confirm'
        );

        return $selected;
    }

    protected function detectClaudeCode(): bool
    {
        // Check for Claude CLI
        if ($this->detectClaudeCli()) {
            return true;
        }

        // Check for Claude desktop app
        $homeDir = $this->getHomeDirectory();
        $desktopPaths = [
            '/Applications/Claude.app',  // macOS
            $homeDir . '/Library/Application Support/Claude', // macOS config
            $homeDir . '/.config/claude', // Linux
            (isset($_SERVER['APPDATA']) ? $_SERVER['APPDATA'] . '/Claude' : null), // Windows
        ];

        foreach ($desktopPaths as $path) {
            if ($path && (File::exists($path) || File::isDirectory($path))) {
                return true;
            }
        }

        return false;
    }

    protected function detectCursor(): bool
    {
        // Check for Cursor application
        $cursorPaths = [
            '/Applications/Cursor.app', // macOS
            '/usr/local/bin/cursor', // Linux
            $this->which('cursor'), // Any system PATH
        ];

        foreach ($cursorPaths as $path) {
            if ($path && File::exists($path)) {
                return true;
            }
        }

        // Check if .cursorrules already exists (indicates Cursor usage)
        if (File::exists(base_path('.cursorrules'))) {
            return true;
        }

        return false;
    }

    protected function detectCline(): bool
    {
        // Check for VS Code installation
        $vscodePaths = [
            '/Applications/Visual Studio Code.app', // macOS
            '/usr/local/bin/code', // Linux
            $this->which('code'), // Any system PATH
        ];

        $vscodeInstalled = false;
        foreach ($vscodePaths as $path) {
            if ($path && File::exists($path)) {
                $vscodeInstalled = true;
                break;
            }
        }

        if (! $vscodeInstalled) {
            return false;
        }

        // Check for existing VS Code settings with Cline configuration
        $vscodeSettings = base_path('.vscode/settings.json');
        if (File::exists($vscodeSettings)) {
            $settings = json_decode(File::get($vscodeSettings), true) ?? [];
            if (isset($settings['cline.mcpServers'])) {
                return true;
            }
        }

        // If VS Code is installed, we assume Cline could be installed
        return true;
    }

    protected function which(string $command): ?string
    {
        try {
            $result = Process::run(['which', $command]);

            return $result->successful() ? trim($result->output()) : null;
        } catch (\Exception $e) {
            return null;
        }
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
            if ($this->option('debug')) {
                $this->line('🐛 Debug: Checking for Claude CLI...');
            }

            // Check for claude command (handles aliases too)
            $result = Process::run(['bash', '-c', 'command -v claude']);
            if ($this->option('debug')) {
                $this->line("🐛 Debug: 'command -v claude' result: " . ($result->successful() ? 'SUCCESS' : 'FAILED'));
                $this->line("🐛 Debug: Output: '" . trim($result->output()) . "'");
                $this->line("🐛 Debug: Error: '" . trim($result->errorOutput()) . "'");
            }
            if ($result->successful()) {
                if ($this->option('debug')) {
                    $this->line('🐛 Debug: Claude CLI detected via command -v');
                }

                return true;
            }

            // Try alternative detection methods
            $alternatives = [
                ['which', 'claude'],
                ['bash', '-c', 'type claude'],
                ['bash', '-c', 'whereis claude'],
            ];

            foreach ($alternatives as $cmd) {
                $result = Process::run($cmd);
                if ($this->option('debug')) {
                    $this->line("🐛 Debug: '" . implode(' ', $cmd) . "' result: " . ($result->successful() ? 'SUCCESS' : 'FAILED'));
                    $this->line("🐛 Debug: Output: '" . trim($result->output()) . "'");
                }
                if ($result->successful() && ! empty(trim($result->output()))) {
                    $output = trim($result->output());

                    // Special handling for whereis which returns "claude:" format
                    if (str_contains($cmd[count($cmd) - 1], 'whereis') && $output === 'claude:') {
                        // whereis found it but didn't provide a path, skip this result
                        if ($this->option('debug')) {
                            $this->line('🐛 Debug: whereis found claude but no path, skipping');
                        }
                        continue;
                    }

                    if ($this->option('debug')) {
                        $this->line('🐛 Debug: Claude CLI detected via ' . implode(' ', $cmd));
                    }

                    return true;
                }
            }

            // Also check for Claude Code JetBrains plugin
            $jetbrainsPlugins = glob($this->getHomeDirectory() . '/Library/Application Support/JetBrains/*/plugins/claude-code-jetbrains-plugin');
            if ($this->option('debug')) {
                $this->line('🐛 Debug: JetBrains plugins found: ' . count($jetbrainsPlugins));
            }
            if (! empty($jetbrainsPlugins)) {
                if ($this->option('debug')) {
                    $this->line('🐛 Debug: Claude CLI detected via JetBrains plugin');
                }

                return true;
            }

            if ($this->option('debug')) {
                $this->line('🐛 Debug: No Claude CLI detected');
            }

            return false;
        } catch (\Exception $e) {
            if ($this->option('debug')) {
                $this->line('🐛 Debug: Exception during Claude CLI detection: ' . $e->getMessage());
            }

            return false;
        }
    }

    protected function configureClaudeCodeModern(string $projectPath): bool
    {
        // Get the Laravel project root
        $laravelRoot = $this->getLaravelRoot();
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
                'mcp:start',
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
        $this->info('🎉 Statamic MCP Server v2.0 installation complete!');
        $this->newLine();

        $this->info('📚 What was configured:');
        $this->line('  • Published MCP server configuration');
        $this->line('  • Ran database migrations (MCP token storage)');
        $this->line('  • Created AI assistant configurations where possible');
        $this->line('  • Added MCP guidelines for better AI understanding');
        $this->newLine();

        $this->info('🔑 API Token Setup:');
        $this->line('  1. Visit the MCP dashboard in your Statamic CP (Tools → MCP)');
        $this->line('  2. Create a new API token with the scopes your agent needs');
        $this->line('  3. Copy the token — it will only be shown once');
        $this->line('  4. For web MCP: Set STATAMIC_MCP_WEB_ENABLED=true in .env');
        $this->newLine();

        $this->info('🚀 Next Steps:');
        $this->line('  1. Restart your AI assistant (Claude Code, Cursor, etc.)');
        $this->line('  2. Test the MCP connection: php artisan mcp:start statamic');
        $this->line('  3. Verify MCP registration: claude mcp list');
        $this->line('  4. Ask your AI: "What Statamic MCP tools are available?"');
        $this->newLine();

        $this->info('📖 Available MCP Tools (Router Architecture):');
        $this->line('  🚦 Domain Routers (9 tools consolidating 140+ operations):');
        $this->line('  • statamic-entries   - Manage entries across all collections (CRUD, publish/unpublish)');
        $this->line('  • statamic-terms     - Manage taxonomy terms (CRUD operations)');
        $this->line('  • statamic-globals   - Manage global set values (list, get, update)');
        $this->line('  • statamic-structures - Collections, taxonomies, navigations, sites (30+ operations)');
        $this->line('  • statamic-assets    - Asset containers and file operations (20+ operations)');
        $this->line('  • statamic-users     - Users, roles, user groups, permissions (25+ operations)');
        $this->line('  • statamic-system    - Cache, health, config, system info (15+ operations)');
        $this->line('  • statamic-blueprints - Blueprint CRUD, scanning, type generation (10+ operations)');
        $this->line('  • statamic-content-facade - Common content workflow shortcuts');
        $this->newLine();
        $this->line('  🎓 Agent Education Tools (2 specialized tools):');
        $this->line('  • statamic-system-discover - Intent-based tool discovery with recommendations');
        $this->line('  • statamic-system-schema   - Detailed tool schema inspection and documentation');
        $this->newLine();

        $this->info('📋 Manual Configuration:');
        $this->line('  • See docs/WEB_MCP_SETUP.md for web MCP endpoint setup');
        $this->line('  • Visit Statamic CP → Tools → MCP for token management');
        $this->newLine();

        $this->info('Happy coding with AI assistance! 🤖✨');
    }

    protected function getCursorRulesContent(string $projectPath): string
    {
        return <<<MARKDOWN
# Statamic MCP Server v2.0 Integration

This project uses Statamic MCP Server v2.0 for enhanced AI-assisted development.
Requires Statamic v6+ and laravel/mcp v0.6+.

## MCP Server Configuration
- Command: php artisan mcp:start statamic
- Project Path: {$projectPath}
- Tools Available: 11 MCP tools (Router Architecture)
- Authentication: Scoped API tokens (manage via CP → Tools → MCP)

## MCP Tools Architecture (Router-Based)

### 🚦 Domain Routers (9 Core Tools)

#### statamic-entries
**Purpose**: Manage entries across all collections
**Actions**: list, get, create, update, delete, publish, unpublish
**Example**: `{"action": "list", "collection": "blog"}`

#### statamic-terms
**Purpose**: Manage taxonomy terms
**Actions**: list, get, create, update, delete
**Example**: `{"action": "list", "taxonomy": "categories"}`

#### statamic-globals
**Purpose**: Manage global set values
**Actions**: list, get, update
**Example**: `{"action": "get", "handle": "site_settings"}`

#### statamic-structures
**Purpose**: Manage all structural elements of your Statamic site
**Actions**: list, get, create, update, delete, reorder
**Handles**: collections (8+ ops), taxonomies (7+ ops), navigations (6+ ops), sites (5+ ops)
**Example**: `{"action": "create", "type": "collection", "handle": "products"}`

#### statamic-assets
**Purpose**: Complete asset and media file management
**Actions**: list, upload, move, rename, delete, metadata, regenerate
**Handles**: containers (5+ ops), assets (15+ ops)
**Example**: `{"action": "upload", "container": "images", "file": "..."}`

#### statamic-users
**Purpose**: User, role, and permission management
**Actions**: list, get, create, update, delete, assign-role, permissions
**Handles**: users (10+ ops), roles (8+ ops), user groups (6+ ops)
**Example**: `{"action": "assign-role", "user": "123", "role": "editor"}`

#### statamic-system
**Purpose**: System operations and maintenance
**Actions**: info, cache-clear, cache-warm, health-check, config, discover
**Handles**: cache (5+ ops), health (3+ ops), config (4+ ops), info (3+ ops)
**Example**: `{"action": "cache-clear", "types": ["stache", "static"]}`

#### statamic-blueprints
**Purpose**: Blueprint schema management and type generation
**Actions**: list, get, create, update, delete, scan, generate, types
**Handles**: all blueprint operations (10+ ops)
**Example**: `{"action": "generate", "type": "typescript", "blueprint": "article"}`

### 🎓 Agent Education Tools (2 Specialized Tools)

#### statamic-discovery
**Purpose**: Help AI agents discover the right tools for their intent
**Features**:
- Intent-based tool recommendations
- Usage examples for common workflows
- Tool capability search
**Example**: `{"intent": "create a blog", "context": "new project"}`

#### statamic-schema
**Purpose**: Detailed schema inspection for any tool
**Features**:
- Complete parameter documentation
- Valid value enumerations
- Response format examples
**Example**: `{"tool": "statamic-entries", "action": "create"}`

## Authentication

Web MCP endpoints require scoped API tokens:
1. Visit Statamic CP → Tools → MCP
2. Create a token with required scopes (e.g., content:read, content:write)
3. Use Bearer token authentication for web requests
4. CLI mode (php artisan mcp:start) runs with full permissions

## Development Guidelines

### Router Pattern Usage

1. **Start with Discovery**:
   - Use `statamic-discovery` to find the right tool for your intent
   - Use `statamic-schema` to understand tool parameters and responses
   - Use `statamic-system` with action "info" for installation details

2. **Router Pattern Syntax**:
   - Each router handles multiple related operations via the "action" parameter
   - Always specify both "action" and relevant type/target parameters
   - Example: `{"action": "list", "type": "entries", "collection": "blog"}`

3. **Content Operations**:
   - Use `statamic-entries` for entry operations (CRUD, publish/unpublish)
   - Use `statamic-terms` for taxonomy term operations
   - Use `statamic-globals` for global set value operations
   - Use `statamic-structures` for collections, taxonomies, navigations
   - Use `statamic-blueprints` for schema management and type generation

4. **Performance & Best Practices**:
   - Router tools consolidate operations for better performance
   - Automatic cache clearing after structural changes
   - Pagination support with limit and offset parameters
   - Dry-run support for safe testing of modifications

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
# Statamic MCP Guidelines (v2.0)

This file provides AI assistants with comprehensive understanding of the Statamic MCP Server v2.0 capabilities.
Requires Statamic v6+ and laravel/mcp v0.6+.

## MCP Server Overview

The Statamic MCP Server uses a router-based architecture with 11 tools:

### Router Architecture (9 + 2 Tools)

**Domain Routers** (9 core tools consolidating 140+ operations):
- **statamic-entries**: Manage entries across all collections (CRUD, publish/unpublish)
- **statamic-terms**: Manage taxonomy terms (CRUD operations)
- **statamic-globals**: Manage global set values (list, get, update)
- **statamic-structures**: Structural elements (collections, taxonomies, navigations, sites - 26+ ops)
- **statamic-assets**: Complete asset management (containers, files, metadata - 20+ ops)
- **statamic-users**: User and permission management (users, roles, groups - 24+ ops)
- **statamic-system**: System operations (cache, health, config, info - 15+ ops)
- **statamic-blueprints**: Schema management (CRUD, scanning, type generation - 10+ ops)

**Agent Education Tools** (2 specialized tools):
- **statamic-discovery**: Intent-based tool discovery and recommendations
- **statamic-schema**: Detailed tool schema inspection and documentation

Use `statamic-discovery` to find the right tool for your intent and `statamic-schema` for detailed documentation.

## Authentication

### Scoped API Tokens
Web MCP endpoints use scoped API tokens for fine-grained access control:
- Tokens are managed via the Statamic CP dashboard (Tools → MCP)
- Each token has specific scopes (e.g., content:read, content:write, system:read)
- Use Bearer token authentication: `Authorization: Bearer <token>`
- CLI mode (php artisan mcp:start) runs with full permissions

### Available Scopes
- `content:read` / `content:write` - Entry and term operations
- `structures:read` / `structures:write` - Collection and taxonomy management
- `assets:read` / `assets:write` - Asset operations
- `users:read` / `users:write` - User management
- `globals:read` / `globals:write` - Global set operations
- `blueprints:read` / `blueprints:write` - Blueprint management
- `system:read` / `system:write` - System operations
- `structures:read` / `structures:write` - Navigation management
- `*` - Wildcard (all permissions)

## Usage Patterns

### Discovery Phase
Always start development sessions with:
1. `statamic-discovery` - Find the right tool for your intent
2. `statamic-system` (action: "info") - Understand the installation
3. `statamic-schema` - Get detailed tool documentation when needed
4. `statamic-structures` (action: "list", type: "collections") - Map content structure

### Development Phase
For content work:
- Use `statamic-entries` for entry CRUD and publishing across collections
- Use `statamic-terms` for taxonomy term management
- Use `statamic-globals` for global set value management
- Use `statamic-structures` for collections, taxonomies, navigations
- Use `statamic-blueprints` for schema management and type generation

For system operations:
- Use `statamic-system` for cache management, health checks, configuration
- Use `statamic-assets` for file and media management
- Use `statamic-users` for user, role, and permission management

### Content Architecture
Create structures with appropriate router tools:

**Creating a Collection:**
Use `statamic-structures` with `{"action": "create", "type": "collection", "handle": "blog"}`

**Creating Blueprints:**
Use `statamic-blueprints` with `{"action": "create", "handle": "article", "fields": [...]}`

**Managing Entries:**
Use `statamic-entries` with `{"action": "create", "collection": "blog", "data": {...}}`

**Managing Terms:**
Use `statamic-terms` with `{"action": "create", "taxonomy": "categories", "data": {...}}`

**Global Settings:**
Use `statamic-globals` with `{"action": "update", "handle": "site_settings", "data": {...}}`

## Statamic Development Best Practices

### Primary Templating Language
- **Antlers-first projects**: Prefer Antlers syntax, use Antlers tags and variables
- **Blade-first projects**: Prefer Blade components, use Statamic Blade tags

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

### Media
- `assets` - File/image management
- `video` - Video embedding

### Relationships
- `entries` - Link to other entries
- `taxonomy` - Link to taxonomy terms
- `users` - Link to user accounts

### Structured Data
- `replicator` - Flexible content blocks
- `grid` - Tabular data
- `group` - Field grouping

## AI Assistant Integration

1. **Start with discovery** - Use `statamic-discovery` to find the right tool
2. **Use router tools** - Each domain router handles multiple operations efficiently
3. **Check schemas** - Use `statamic-schema` for detailed parameter documentation
4. **Respect scopes** - Ensure your token has required scopes for the operations
5. **Follow router patterns** - Always use action-based syntax for consistent behavior

## Error Handling

All router tools provide consistent error responses. When tools return errors:
- Use `statamic-discovery` to find the correct tool and action
- Use `statamic-schema` to verify parameter requirements
- Check token scopes if you receive permission errors
- Validate project state with appropriate router tools
MARKDOWN;
    }

    protected function tryRegisterMcpCommand(string $laravelRoot): void
    {
        // Try to register with Claude MCP command if available
        try {
            // Use the same detection logic as detectClaudeCli()
            if (! $this->detectClaudeCli()) {
                $this->line('  ℹ️  Claude CLI not detected, skipping automatic registration');

                return; // Claude CLI not available
            }

            $this->line('  🔄 Attempting to register with Claude CLI...');

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
            } else {
                $this->warn('⚠️  Claude CLI registration failed, but .mcp.json was created');
                if ($this->getOutput()->isVerbose()) {
                    $this->line('  Error: ' . $result->errorOutput());
                    $this->line('  Output: ' . $result->output());
                }
                $this->line('  💡 You can manually register with: claude mcp add statamic "php artisan mcp:start statamic" --scope project');
            }
        } catch (\Exception $e) {
            $this->warn('⚠️  Error during Claude CLI registration: ' . $e->getMessage());
            $this->line('  💡 You can manually register with: claude mcp add statamic "php artisan mcp:start statamic" --scope project');
        }
    }

    protected function getHomeDirectory(): string
    {
        return $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';
    }

    protected function getLaravelRoot(): string
    {
        // Start from base_path() and walk up to find the Laravel root
        $current = base_path();

        // If we're in an addon directory, we need to find the actual Laravel project root
        while ($current && $current !== '/') {
            // Check if this directory contains artisan and composer.json (Laravel project root)
            if (File::exists($current . '/artisan') && File::exists($current . '/composer.json')) {
                return $current;
            }

            // Go up one directory
            $parent = dirname($current);
            if ($parent === $current) {
                break; // Reached the root directory
            }
            $current = $parent;
        }

        // Fallback to base_path if we can't find Laravel root
        return base_path();
    }
}
