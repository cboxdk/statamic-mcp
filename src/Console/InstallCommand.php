<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Auth\V4ClientPermissions;
use Cboxdk\StatamicMcp\Auth\V4TokenMigration;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\OAuth\Drivers\DatabaseOAuthDriver;
use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class InstallCommand extends Command
{
    protected $signature = 'mcp:statamic:install {--profile= : Explicit stdio profile: read_only, editor or operator} {--force : Overwrite existing configuration} {--upgrade : Treat this as an upgrade of an existing installation} {--skip-migrations : Skip database migrations} {--debug : Show debug information}';

    private string $stdioProfile;

    protected $description = 'Install Statamic MCP Server, or upgrade an existing installation, and configure AI assistants';

    public function handle(): int
    {
        if (app()->configurationIsCached()) {
            $this->error('Run php artisan config:clear before installing, then rebuild the configuration cache after mcp:doctor passes.');

            return self::FAILURE;
        }

        $upgrading = $this->detectExistingInstallation();

        $profile = $this->option('profile') ?? config('statamic.mcp.profiles.stdio');
        if (! is_string($profile) || ! in_array($profile, self::PROFILES, true)) {
            if (! $this->input->isInteractive() || $this->option('profile') !== null) {
                $this->error('Choose --profile=read_only, --profile=editor or --profile=operator before installing.');

                return self::FAILURE;
            }
            $profile = $this->askForProfile();
        }
        $this->stdioProfile = $profile;

        $this->info(($upgrading ? 'Upgrading' : 'Installing') . ' Statamic MCP Server with stdio profile: ' . $profile);
        $this->newLine();

        if ($upgrading) {
            // An existing config is the operator's: keep it unless asked.
            $this->line('Existing installation found. Your configuration is kept; new settings fall back to the package defaults.');
            $this->newLine();
            if ($this->option('force')) {
                $this->publishConfiguration();
            }
        } else {
            $this->publishConfiguration();
        }

        $this->writeProfileToEnv();
        $this->runMigrations();

        if ($upgrading) {
            $this->reviewTokensForV4();
            if (! $this->reviewClientPermissionsForV4()) {
                return self::FAILURE;
            }
        }

        $this->configureAiAssistants();
        $this->createMcpGuidelines();

        $this->info('🩺 Checking the configuration (mcp:doctor)...');
        $doctor = $this->call('mcp:doctor');
        $this->newLine();

        if ($doctor !== self::SUCCESS) {
            $this->error('Configuration was written, but MCP is not ready. Fix the reported settings and run php artisan mcp:doctor again.');

            return $doctor;
        }
        $this->showCompletionMessage();

        return self::SUCCESS;
    }

    private const PROFILES = ['read_only', 'editor', 'operator'];

    protected function reviewClientPermissionsForV4(): bool
    {
        $migration = app(V4ClientPermissions::class);
        $reports = $migration->run();
        foreach ($reports as $report) {
            $this->line($report['path']);
            foreach ($report['changes'] as $change) {
                $this->line('  ' . $change);
            }
            if ($report['error'] !== null) {
                $this->error($report['error']);

                return false;
            }
        }
        if ($reports !== [] && $this->confirm('Migrate these project-local client permission rules? Only reads stay auto-approved; a .pre-mcp-v4 backup is saved.', false)) {
            foreach ($migration->run(true) as $report) {
                if ($report['error'] !== null) {
                    $this->error($report['path'] . ': ' . $report['error']);

                    return false;
                }
            }
        }
        $this->line('Review global client permissions, wildcard rules and servers named other than statamic manually. See UPGRADE.md.');

        return true;
    }

    /**
     * An installation exists when the config was published or tokens were
     * issued; either means a fresh install would overwrite someone's setup.
     */
    protected function detectExistingInstallation(): bool
    {
        if ($this->option('upgrade')) {
            return true;
        }

        if (File::exists(config_path('statamic/mcp.php'))) {
            return true;
        }

        try {
            return app(TokenStore::class)->listAll()->isNotEmpty();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function askForProfile(): string
    {
        $choice = select(
            label: 'What may AI assistants do through the local (stdio) server?',
            options: [
                'read_only' => 'Read only: inspect content and structure, change nothing',
                'editor' => 'Editor: draft and edit unpublished entries',
                'operator' => 'Operator: everything your account may do, including administration',
            ],
            default: 'read_only',
        );

        return is_string($choice) && in_array($choice, self::PROFILES, true) ? $choice : 'read_only';
    }

    /**
     * Persist the chosen profile so `mcp:start` works without flags. An
     * existing value is only replaced after confirmation.
     */
    protected function writeProfileToEnv(): void
    {
        $envPath = base_path('.env');
        if (! File::exists($envPath)) {
            $this->line("ℹ️  No .env file found. Set STATAMIC_MCP_STDIO_PROFILE={$this->stdioProfile} in your environment.");
            $this->newLine();

            return;
        }

        $env = File::get($envPath);
        $line = 'STATAMIC_MCP_STDIO_PROFILE=' . $this->stdioProfile;

        if (preg_match('/^STATAMIC_MCP_STDIO_PROFILE=(.*)$/m', $env, $match) === 1) {
            if (trim($match[1]) === $this->stdioProfile) {
                return;
            }
            if (! $this->confirm("Change STATAMIC_MCP_STDIO_PROFILE in .env from '" . trim($match[1]) . "' to '{$this->stdioProfile}'?", true)) {
                return;
            }
            File::put($envPath, (string) preg_replace('/^STATAMIC_MCP_STDIO_PROFILE=.*$/m', $line, $env));
        } else {
            File::append($envPath, (str_ends_with($env, "\n") || $env === '' ? '' : "\n") . $line . "\n");
        }

        // Doctor runs in this process; .env changes do not reload Laravel config.
        config(['statamic.mcp.profiles.stdio' => $this->stdioProfile]);
        $this->info("✅ Saved {$line} to .env");
        $this->newLine();
    }

    /**
     * v4 narrowed what some v3 tokens may do. Show the same plan as
     * mcp:migrate-v4 and let the operator carry it out now, instead of
     * leaving token holders to discover it from a refusal. Nothing is
     * widened: changed scopes need consent.
     */
    protected function reviewTokensForV4(): void
    {
        $this->info('🔑 Reviewing tokens for v4 scope changes...');

        $migration = app(V4TokenMigration::class);
        try {
            $steps = $migration->plan(V4TokenMigration::UPGRADE);
        } catch (\Throwable) {
            $this->warn('⚠️  The token store could not be read; run php artisan mcp:doctor after fixing storage.');
            $this->newLine();

            return;
        }

        if ($steps === []) {
            $this->info('✅ No token is affected.');
            $this->newLine();

            return;
        }

        $this->table(['Action', 'Token', 'Owner', 'What changes in v4'], array_map(static fn (array $step): array => [
            $step['action'] === 'narrow' ? 'narrow → ' . implode(', ', $step['after'] ?? []) : $step['action'],
            $step['name'] . ($step['oauth_client_id'] !== null ? ' (OAuth)' : ''),
            $step['owner'],
            $step['reason'],
        ], $steps));

        $changing = count(array_filter($steps, static fn (array $step): bool => $step['action'] !== 'keep'));
        if ($changing === 0) {
            $this->newLine();

            return;
        }

        if ($this->confirm("Apply the {$changing} revoke/narrow step(s) now? Nothing gains access; revoked owners create a new token or authorize the client again.", false)) {
            try {
                $result = $migration->apply(V4TokenMigration::UPGRADE, $steps);
                $result['error'] === null
                    ? $this->info("✅ Applied {$result['applied']} step(s). Journal: {$result['journal']}")
                    : $this->error($result['error']);
            } catch (\Throwable) {
                $this->error('The migration journal could not be written, so nothing was changed.');
            }
        } else {
            $this->line('Nothing was changed. Run php artisan mcp:migrate-v4 --apply when ready.');
        }

        $this->newLine();
    }

    protected function publishConfiguration(): void
    {
        $this->info('📋 Publishing configuration...');

        $configExists = File::exists(config_path('statamic/mcp.php'));
        $forcePublish = $this->option('force');

        if ($configExists && ! $forcePublish) {
            if (! $this->confirm('Configuration already exists. Overwrite?')) {
                $this->info('⏭️  Skipping configuration publish.');
                $this->newLine();

                return;
            }

            // User confirmed overwrite — force the publish
            $forcePublish = true;
        }

        $this->call('vendor:publish', [
            '--tag' => 'statamic-mcp-config',
            '--force' => $forcePublish,
        ]);

        $this->info('✅ Configuration published successfully.');
        $this->newLine();
    }

    protected function runMigrations(): void
    {
        if ($this->option('skip-migrations')) {
            $this->info('⏭️  Skipping migrations (--skip-migrations flag).');
            $this->newLine();

            return;
        }

        if (! $this->requiresDatabaseMigrations()) {
            $this->info('⏭️  Skipping migrations — file-based storage drivers configured (no database needed).');
            $this->newLine();

            return;
        }

        $this->info('🗄️  Running migrations...');

        try {
            $this->call('migrate', [
                '--force' => $this->option('force'),
            ]);

            $this->info('✅ Migrations completed successfully.');
        } catch (\Exception $e) {
            $this->error('❌ Migration failed: ' . $e->getMessage());
            $this->newLine();
            $this->warn('💡 If you don\'t need database storage, set file-based drivers in config/statamic/mcp.php:');
            $this->line('   \'stores\' => [');
            $this->line('       \'tokens\' => \\Cboxdk\\StatamicMcp\\Storage\\Tokens\\FileTokenStore::class,');
            $this->line('       \'audit\'  => \\Cboxdk\\StatamicMcp\\Storage\\Audit\\FileAuditStore::class,');
            $this->line('   ]');
            $this->newLine();
            $this->warn('💡 Or re-run with: php artisan mcp:statamic:install --skip-migrations');
        }

        $this->newLine();
    }

    /**
     * Check if any configured storage driver requires database migrations.
     */
    protected function requiresDatabaseMigrations(): bool
    {
        $tokenDriver = config('statamic.mcp.stores.tokens');
        $auditDriver = config('statamic.mcp.stores.audit');
        $oauthDriver = config('statamic.mcp.oauth.driver');

        return (is_string($tokenDriver) && is_a($tokenDriver, DatabaseTokenStore::class, true))
            || (is_string($auditDriver) && is_a($auditDriver, DatabaseAuditStore::class, true))
            || (is_string($oauthDriver) && is_a($oauthDriver, DatabaseOAuthDriver::class, true));
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
        // Claude Code reads project-local .mcp.json even when the CLI is not on PATH.
        return $this->configureClaudeCodeModern($projectPath);
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
        // One registration, not a second `claude mcp add` after writing the file.
        return $this->writeClientConfig($projectPath . '/.mcp.json', $projectPath);
    }

    protected function configureCursor(string $projectPath): bool
    {
        $cursorrules = base_path('.cursorrules');

        if (File::exists($cursorrules) && ! $this->option('force')) {
            if (! $this->confirm('.cursorrules file exists. Update it?')) {
                return false;
            }
        }

        $content = File::exists($cursorrules) ? File::get($cursorrules) : '';
        $start = '<!-- statamic-mcp:start -->';
        $end = '<!-- statamic-mcp:end -->';
        if (substr_count($content, $start) !== substr_count($content, $end) || substr_count($content, $start) > 1
            || (str_contains($content, $end) && strpos($content, $end) < strpos($content, $start))) {
            $this->warn('Malformed Statamic MCP block in .cursorrules. Repair its start/end markers before retrying.');

            return false;
        }
        if (! $this->writeClientConfig($projectPath . '/.cursor/mcp.json', $projectPath)) {
            return false;
        }
        $block = $start . "\n" . $this->getCursorRulesContent($projectPath) . "\n" . $end;
        $content = str_contains($content, $start)
            ? substr_replace($content, $block, strpos($content, $start), strpos($content, $end) + strlen($end) - strpos($content, $start))
            : $content . ($content === '' ? '' : "\n\n") . $block . "\n";
        File::put($cursorrules, $content);

        return true;
    }

    protected function configureCline(string $projectPath): bool
    {
        $this->line('Open Cline → MCP Servers → Configure MCP Servers and add the stdio configuration from Tools → MCP → Connect. No VS Code settings were changed.');

        return false;
    }

    private function writeClientConfig(string $path, string $projectPath): bool
    {
        try {
            $config = File::exists($path) ? json_decode(File::get($path), flags: JSON_THROW_ON_ERROR) : new \stdClass;
            if (! $config instanceof \stdClass || (property_exists($config, 'mcpServers') && ! $config->mcpServers instanceof \stdClass)) {
                throw new \UnexpectedValueException;
            }
        } catch (\JsonException|\UnexpectedValueException) {
            $this->warn("Invalid JSON object in {$path}. It was not changed.");

            return false;
        }
        if (isset($config->mcpServers->statamic) && ! $this->option('force')
            && ! $this->confirm("Statamic MCP configuration exists in {$path}. Update it?")) {
            return false;
        }
        $config->mcpServers ??= new \stdClass;
        $config->mcpServers->statamic = ['command' => 'php',
            'args' => [$projectPath . '/artisan', 'mcp:start', 'statamic'],
            'env' => ['STATAMIC_MCP_STDIO_PROFILE' => $this->stdioProfile]];
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

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
        $this->info('🎉 Statamic MCP Server is ready!');
        $this->newLine();

        $this->info('📚 What was configured:');
        $this->line('  • Published MCP server configuration');
        if ($this->requiresDatabaseMigrations() && ! $this->option('skip-migrations')) {
            $this->line('  • Ran database migrations (MCP token storage)');
        } else {
            $this->line('  • Using file-based storage (no database required)');
        }
        $this->line('  • Created AI assistant configurations where possible');
        $this->line('  • Added MCP guidelines for better AI understanding');
        $this->newLine();

        $this->info('🔑 API Token Setup:');
        $this->line('  1. Visit the MCP dashboard in your Statamic CP (Tools → MCP)');
        $this->line('  2. Create a new API token with the scopes your agent needs');
        $this->line('  3. Copy the token — it will only be shown once');
        $this->line('  4. For web MCP: Set STATAMIC_MCP_WEB_ENABLED=true and STATAMIC_MCP_HTTP_PROFILE=read_only in .env');
        $this->newLine();

        $this->info('🚀 Next Steps:');
        $this->line('  1. Restart your AI assistant (Claude Code, Cursor, etc.)');
        $this->line('  2. Test the MCP connection: STATAMIC_MCP_STDIO_PROFILE=' . $this->stdioProfile . ' php artisan mcp:start statamic');
        $this->line('  3. Verify MCP registration: claude mcp list');
        $this->line('  4. Ask your AI: "What Statamic MCP tools are available?"');
        $this->newLine();

        $this->info('Available MCP tools:');
        $this->line('  • statamic-discover describes available tools, actions and schemas.');
        $this->line('  • Domain routers expose separate -read and -write/-admin tools.');
        $this->line('  • Your installation profile and token scopes determine which tools are available.');
        $this->newLine();

        $this->info('📋 Manual Configuration:');
        $this->line('  • See docs/quickstart.md for setup and UPGRADE.md for v4 migration');
        $this->line('  • Visit Statamic CP → Tools → MCP for token management');
        $this->newLine();

        $this->info('Happy coding with AI assistance! 🤖✨');
    }

    protected function getCursorRulesContent(string $projectPath): string
    {
        return $this->getStatamicMcpGuidelines();
    }

    protected function getStatamicMcpGuidelines(): string
    {
        return <<<'MARKDOWN'
# Statamic MCP v4

Domain routers share an action parameter. Read tools end in `-read`; content writes end in `-write`, and administrative writes in `-admin`. `statamic-discover` describes available tools and accepts `tool_name` for a tool's live input/output schema. Availability depends on the installation profile and the caller's permissions.

Read the collection blueprint with `statamic-blueprints-read` before composing field values; its `_format_spec` describes this site's wire formats. Read the record before editing and send its `version` as `expected_version`. Use `limit` and the returned `next_cursor` for lists; use `fields` projections or blueprint `field` paths for large records.

Writes require the person's requested change and the appropriate scopes. `entries:write` does not grant publish or delete. `*` is expanded at consent, not stored as a wildcard. On `APPROVAL_REQUIRED`, present the Control Panel approval link and wait; do not attempt to approve it yourself. On `OUTCOME_UNKNOWN`, reconcile the record rather than retrying a write with a new idempotency key.

The workflow prompts `write_entry`, `translate_entry`, `edit_page_builder` and `model_content` prepare authorized context without writing. Blueprints and fieldsets share `statamic-blueprints-read` and `statamic-blueprints-admin`; inspect their current schema before applying a proposed model.

HTTP uses scoped Bearer tokens or OAuth. Local `php artisan mcp:start statamic` needs an explicitly configured stdio profile. Client auto-approval rules do not replace server-side authorization. See UPGRADE.md before changing those rules.
MARKDOWN;
    }

    protected function getHomeDirectory(): string
    {
        return $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';
    }
}
