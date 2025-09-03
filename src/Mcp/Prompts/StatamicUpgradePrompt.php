<?php

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Laravel\Mcp\Server\Prompts\Arguments;
use Laravel\Mcp\Server\Prompts\PromptResult;

class StatamicUpgradePrompt extends Prompt
{
    protected string $description = 'Comprehensive guidance for upgrading between Statamic versions, including breaking changes, migration steps, and troubleshooting';

    public function arguments(): Arguments
    {
        return (new Arguments)
            ->add(new Argument(
                name: 'from_version',
                description: 'Source version: v2, v3, v4, or v5',
                required: true,
            ))
            ->add(new Argument(
                name: 'to_version',
                description: 'Target version: v3, v4, v5, or v6',
                required: true,
            ))
            ->add(new Argument(
                name: 'upgrade_area',
                description: 'Specific area: core, addons, templates, content, or complete',
                required: false,
            ))
            ->add(new Argument(
                name: 'current_issues',
                description: 'Specific issues encountered during upgrade process',
                required: false,
            ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): PromptResult
    {
        $fromVersion = $arguments['from_version'] ?? 'v5';
        $toVersion = $arguments['to_version'] ?? 'v6';
        $upgradeArea = $arguments['upgrade_area'] ?? 'complete';
        $currentIssues = $arguments['current_issues'] ?? '';

        if (! $this->isValidUpgradePath($fromVersion, $toVersion)) {
            return new PromptResult(
                content: "# Invalid Upgrade Path\n\nThe upgrade path from {$fromVersion} to {$toVersion} is not supported or recommended. Please check the official upgrade documentation.",
                description: "Invalid upgrade path from {$fromVersion} to {$toVersion}"
            );
        }

        $content = "# Statamic Upgrade Guide: {$fromVersion} → {$toVersion}\n\n";
        $content .= "Complete migration guidance for upgrading your Statamic installation.\n\n";

        // Add issue-specific help first if provided
        if (! empty($currentIssues)) {
            $content .= $this->getIssueSpecificHelp($currentIssues, $fromVersion, $toVersion);
        }

        // Add pre-upgrade preparation
        $content .= $this->getPreUpgradePreparation($fromVersion, $toVersion);

        // Add version-specific upgrade guidance
        $content .= $this->getUpgradeGuidance($fromVersion, $toVersion, $upgradeArea);

        // Add post-upgrade validation
        $content .= $this->getPostUpgradeValidation($fromVersion, $toVersion);

        // Add troubleshooting section
        $content .= $this->getTroubleshootingGuidance($fromVersion, $toVersion);

        return new PromptResult(
            content: $content,
            description: "Upgrade guidance from {$fromVersion} to {$toVersion}"
        );
    }

    private function isValidUpgradePath(string $from, string $to): bool
    {
        $validPaths = [
            'v2' => ['v3'],
            'v3' => ['v4'],
            'v4' => ['v5'],
            'v5' => ['v6'],
        ];

        return isset($validPaths[$from]) && in_array($to, $validPaths[$from]);
    }

    private function getIssueSpecificHelp(string $issues, string $from, string $to): string
    {
        $content = "## 🚨 Addressing Current Issues\n\n";
        $content .= "**Your reported issues**: {$issues}\n\n";

        // Common issue patterns and solutions
        $commonIssues = [
            'composer' => [
                'pattern' => ['composer', 'dependency', 'require', 'conflict'],
                'solution' => "### Composer Dependency Issues\n" .
                    "1. Clear composer cache: `composer clear-cache`\n" .
                    "2. Remove vendor directory: `rm -rf vendor/`\n" .
                    "3. Update with dependencies: `composer update statamic/cms --with-dependencies`\n" .
                    "4. If conflicts persist, try: `composer update --ignore-platform-reqs`\n\n",
            ],
            'php_version' => [
                'pattern' => ['php version', 'minimum php', 'php 8', 'php requirement'],
                'solution' => "### PHP Version Requirements\n" .
                    "- **{$to}** requires specific PHP versions:\n" .
                    "  - v5: PHP 8.1+\n" .
                    "  - v6: PHP 8.1+ (recommended 8.2+)\n" .
                    "- Update your PHP version before upgrading\n" .
                    "- Check Laravel compatibility requirements\n\n",
            ],
            'laravel_version' => [
                'pattern' => ['laravel', 'illuminate', 'framework'],
                'solution' => "### Laravel Version Compatibility\n" .
                    "- **v4**: Laravel 8-10\n" .
                    "- **v5**: Laravel 10+\n" .
                    "- **v6**: Laravel 10-11\n" .
                    "- Upgrade Laravel first, then Statamic\n" .
                    "- Consider using Laravel Shift for Laravel upgrades\n\n",
            ],
            'control_panel' => [
                'pattern' => ['control panel', 'cp', 'vue', 'javascript', 'ui'],
                'solution' => "### Control Panel Issues\n" .
                    "- Clear browser cache and local storage\n" .
                    "- Run `php artisan statamic:install` after upgrade\n" .
                    "- For v6: Expect Vue 3 changes, update custom components\n" .
                    "- Check addon compatibility with new CP version\n\n",
            ],
            'cache_issues' => [
                'pattern' => ['cache', 'stache', 'views', 'config'],
                'solution' => "### Cache-Related Issues\n" .
                    "```bash\n" .
                    "php artisan cache:clear\n" .
                    "php artisan config:clear\n" .
                    "php artisan view:clear\n" .
                    "php artisan statamic:stache:clear\n" .
                    "```\n\n",
            ],
        ];

        foreach ($commonIssues as $key => $issueData) {
            foreach ($issueData['pattern'] as $pattern) {
                if (stripos($issues, $pattern) !== false) {
                    $content .= $issueData['solution'];
                    break 2;
                }
            }
        }

        return $content;
    }

    private function getPreUpgradePreparation(string $from, string $to): string
    {
        $content = "## 📋 Pre-Upgrade Checklist\n\n";

        $content .= "### 1. Backup Everything\n";
        $content .= "```bash\n";
        $content .= "# Database backup\n";
        $content .= "mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql\n\n";
        $content .= "# File system backup\n";
        $content .= "tar -czf site_backup_$(date +%Y%m%d).tar.gz .\n\n";
        $content .= "# Git commit (if using version control)\n";
        $content .= "git add -A && git commit -m \"Pre-upgrade backup - {$from} to {$to}\"\n";
        $content .= "```\n\n";

        $content .= "### 2. Environment Analysis\n";
        $content .= "**Use these MCP tools for comprehensive analysis**:\n";
        $content .= "- `statamic.system.info` - Check current version and environment\n";
        $content .= "- `statamic.addons.scan` - Analyze addon compatibility\n";
        $content .= "- `statamic.blueprints.scan` - Document current blueprint structure\n";
        $content .= "- `statamic.content.extract` - Analyze content structure\n\n";

        $content .= "### 3. Requirements Verification\n";
        $requirementsMap = [
            'v3' => ['PHP' => '7.4+', 'Laravel' => '6-8'],
            'v4' => ['PHP' => '8.0+', 'Laravel' => '8-10'],
            'v5' => ['PHP' => '8.1+', 'Laravel' => '10+'],
            'v6' => ['PHP' => '8.1+', 'Laravel' => '10-11', 'Node' => '18+', 'Vue' => '3'],
        ];

        if (isset($requirementsMap[$to])) {
            $content .= "**{$to} Requirements**:\n";
            foreach ($requirementsMap[$to] as $tech => $version) {
                $content .= "- {$tech}: {$version}\n";
            }
            $content .= "\n";
        }

        return $content;
    }

    private function getUpgradeGuidance(string $from, string $to, string $area): string
    {
        $content = "## 🚀 Upgrade Process: {$from} → {$to}\n\n";

        // Version-specific upgrade steps
        switch ("{$from}-to-{$to}") {
            case 'v4-to-v5':
                $content .= $this->getV4ToV5Upgrade($area);
                break;
            case 'v5-to-v6':
                $content .= $this->getV5ToV6Upgrade($area);
                break;
            case 'v3-to-v4':
                $content .= $this->getV3ToV4Upgrade($area);
                break;
            case 'v2-to-v3':
                $content .= $this->getV2ToV3Upgrade($area);
                break;
            default:
                $content .= $this->getGenericUpgrade($from, $to, $area);
        }

        return $content;
    }

    private function getV4ToV5Upgrade(string $area): string
    {
        $content = "### Core v4 → v5 Changes\n\n";

        $content .= "#### 1. Composer Update\n";
        $content .= "```bash\n";
        $content .= "# Update composer.json\n";
        $content .= "# Change \"statamic/cms\": \"^4.0\" to \"statamic/cms\": \"^5.0\"\n";
        $content .= "composer update statamic/cms --with-dependencies\n";
        $content .= "```\n\n";

        $content .= "#### 2. High Impact Changes\n";
        $content .= "**PHP & Laravel Requirements**:\n";
        $content .= "- Minimum PHP 8.1 (upgrade if needed)\n";
        $content .= "- Minimum Laravel 10 (upgrade Laravel first if needed)\n\n";

        $content .= "**Site Configuration Migration**:\n";
        $content .= "```bash\n";
        $content .= "# Move config/statamic/sites.php to resources/sites.yaml\n";
        $content .= "# Add multisite boolean to config/statamic/system.php\n";
        $content .= "'multisite' => false,  # or true if you have multiple sites\n";
        $content .= "```\n\n";

        $content .= "#### 3. Medium Impact Changes\n";
        $content .= "**Blueprint Default Values**:\n";
        $content .= "- Default values now populate automatically\n";
        $content .= "- Review blueprints with default values for unexpected behavior\n\n";

        $content .= "**Laravel Helpers Removed**:\n";
        $content .= "- Replace `array_get()` with `Arr::get()`\n";
        $content .= "- Replace `str_*()` helpers with `Str::*()` methods\n\n";

        $content .= "#### 4. Validation & Testing\n";
        $content .= "```bash\n";
        $content .= "# Clear all caches\n";
        $content .= "php artisan cache:clear\n";
        $content .= "php artisan config:clear\n";
        $content .= "php artisan view:clear\n";
        $content .= "php artisan statamic:stache:clear\n\n";
        $content .= "# Re-install Statamic\n";
        $content .= "php artisan statamic:install\n\n";
        $content .= "# Test critical functionality\n";
        $content .= "php artisan statamic:assets:generate-presets\n";
        $content .= "```\n\n";

        return $content;
    }

    private function getV5ToV6Upgrade(string $area): string
    {
        $content = "### Core v5 → v6 Changes (Vue 3 Migration)\n\n";

        $content .= "⚠️ **Major Control Panel Changes**: Statamic v6 upgrades to Vue 3 with new UI Kit\n\n";

        $content .= "#### 1. Preparation Phase\n";
        $content .= "```bash\n";
        $content .= "# Audit custom addons and components\n";
        $content .= "find . -name '*.vue' -not -path './vendor/*' | head -20\n";
        $content .= "grep -r 'Vue.component' resources/ --include='*.js'\n";
        $content .= "grep -r 'new Vue' resources/ --include='*.js'\n";
        $content .= "```\n\n";

        $content .= "#### 2. Composer Update\n";
        $content .= "```bash\n";
        $content .= "# Update composer.json\n";
        $content .= "# Change \"statamic/cms\": \"^5.0\" to \"statamic/cms\": \"^6.0\"\n";
        $content .= "composer update statamic/cms --with-dependencies\n";
        $content .= "```\n\n";

        $content .= "#### 3. Vue 3 Migration Requirements\n";
        $content .= "**Control Panel Components**:\n";
        $content .= "- All custom Vue components need Vue 3 compatibility\n";
        $content .= "- Update to Composition API (recommended) or compatible Options API\n";
        $content .= "- Replace deprecated Vue 2 patterns\n\n";

        $content .= "**UI Kit Integration**:\n";
        $content .= "```javascript\n";
        $content .= "// OLD Vue 2 pattern\n";
        $content .= "Vue.component('my-component', {...})\n\n";
        $content .= "// NEW Vue 3 pattern with UI Kit\n";
        $content .= "import { defineComponent } from 'vue'\n";
        $content .= "import { UIButton, UICard } from '@statamic/ui-kit'\n\n";
        $content .= "export default defineComponent({\n";
        $content .= "  components: { UIButton, UICard },\n";
        $content .= "  // ... component definition\n";
        $content .= "})\n";
        $content .= "```\n\n";

        $content .= "#### 4. Breaking Changes\n";
        $content .= "**Vue 3 Specific**:\n";
        $content .= "- `\$children` removed (use refs or provide/inject)\n";
        $content .= "- `\$listeners` merged into `\$attrs`\n";
        $content .= "- Event bus patterns need replacement\n";
        $content .= "- Filters removed (use computed properties or methods)\n\n";

        $content .= "**Addon Development**:\n";
        $content .= "- Update addon Vue components for Vue 3\n";
        $content .= "- Use new UI Kit components for consistency\n";
        $content .= "- Test all control panel customizations thoroughly\n\n";

        return $content;
    }

    private function getV3ToV4Upgrade(string $area): string
    {
        $content = "### Core v3 → v4 Changes\n\n";

        $content .= "#### 1. Requirements Update\n";
        $content .= "- Minimum PHP 8.0+\n";
        $content .= "- Laravel 8-10 support\n\n";

        $content .= "#### 2. Composer Update\n";
        $content .= "```bash\n";
        $content .= "composer update statamic/cms --with-dependencies\n";
        $content .= "```\n\n";

        $content .= "#### 3. Breaking Changes\n";
        $content .= "**Component Naming**:\n";
        $content .= "- `<portal-vue>` → `<v-portal>`\n";
        $content .= "- `<vue-modal>` → `<v-modal>`\n\n";

        $content .= "**Status Filtering**:\n";
        $content .= "- Status filters now only work with `is/equals` conditions\n";
        $content .= "- Use `whereStatus()` query method for programmatic filtering\n\n";

        return $content;
    }

    private function getV2ToV3Upgrade(string $area): string
    {
        $content = "### Major v2 → v3 Migration\n\n";

        $content .= "⚠️ **This is a major architectural change** - requires comprehensive migration.\n\n";

        $content .= "#### Migration Strategy\n";
        $content .= "1. **Fresh Installation Recommended**: Install v3 fresh and migrate content\n";
        $content .= "2. **Content Migration**: Use official migration tools\n";
        $content .= "3. **Template Conversion**: Templates require significant updates\n";
        $content .= "4. **Addon Replacement**: Most v2 addons need v3 equivalents\n\n";

        $content .= "#### Key Changes\n";
        $content .= "- Laravel-based architecture (vs. flat-file v2)\n";
        $content .= "- New file structure and organization\n";
        $content .= "- Updated Antlers syntax and features\n";
        $content .= "- New Control Panel built in Vue.js\n";
        $content .= "- Git-based workflow support\n\n";

        $content .= "**Recommendation**: Follow the official [v2 to v3 migration guide](https://statamic.dev/upgrade-guide/v2-to-v3) closely.\n\n";

        return $content;
    }

    private function getGenericUpgrade(string $from, string $to, string $area): string
    {
        $content = "### Generic Upgrade Process\n\n";

        $content .= "#### 1. Basic Steps\n";
        $content .= "```bash\n";
        $content .= "# Update composer requirement\n";
        $content .= "composer update statamic/cms --with-dependencies\n\n";
        $content .= "# Clear caches\n";
        $content .= "php artisan cache:clear\n";
        $content .= "php artisan config:clear\n";
        $content .= "php artisan view:clear\n";
        $content .= "php artisan statamic:stache:clear\n\n";
        $content .= "# Reinstall Statamic\n";
        $content .= "php artisan statamic:install\n";
        $content .= "```\n\n";

        $content .= "#### 2. Validation\n";
        $content .= "- Test control panel access\n";
        $content .= "- Verify frontend functionality\n";
        $content .= "- Check addon compatibility\n";
        $content .= "- Test content editing and publishing\n\n";

        return $content;
    }

    private function getPostUpgradeValidation(string $from, string $to): string
    {
        $content = "## ✅ Post-Upgrade Validation\n\n";

        $content .= "### 1. System Verification\n";
        $content .= "**Use MCP tools for comprehensive testing**:\n";
        $content .= "```bash\n";
        $content .= "# Verify upgrade success\n";
        $content .= "php artisan statamic:version\n\n";
        $content .= "# Check system status\n";
        $content .= "# Use: statamic.system.info\n";
        $content .= "# Use: statamic.cache.manage with action 'status'\n";
        $content .= "# Use: statamic.addons.scan for addon compatibility\n";
        $content .= "```\n\n";

        $content .= "### 2. Functional Testing Checklist\n";
        $content .= "- [ ] Control panel loads without errors\n";
        $content .= "- [ ] Can login with existing credentials\n";
        $content .= "- [ ] Collections display correctly\n";
        $content .= "- [ ] Entries can be created and edited\n";
        $content .= "- [ ] Frontend pages render correctly\n";
        $content .= "- [ ] Forms work (if using)\n";
        $content .= "- [ ] Asset uploads function\n";
        $content .= "- [ ] Search functionality works\n";
        $content .= "- [ ] Static caching works (if enabled)\n";
        $content .= "- [ ] Email functionality works\n\n";

        $content .= "### 3. Performance Verification\n";
        $content .= "```bash\n";
        $content .= "# Clear and warm caches\n";
        $content .= "php artisan statamic:stache:warm\n";
        $content .= "php artisan statamic:static:clear\n";
        $content .= "php artisan statamic:static:warm\n\n";
        $content .= "# Generate asset presets\n";
        $content .= "php artisan statamic:assets:generate-presets\n";
        $content .= "```\n\n";

        return $content;
    }

    private function getTroubleshootingGuidance(string $from, string $to): string
    {
        $content = "## 🔧 Common Upgrade Issues & Solutions\n\n";

        $content .= "### Composer/Dependency Issues\n";
        $content .= "```bash\n";
        $content .= "# Clear composer cache\n";
        $content .= "composer clear-cache\n\n";
        $content .= "# Remove vendor and reinstall\n";
        $content .= "rm -rf vendor/ composer.lock\n";
        $content .= "composer install\n\n";
        $content .= "# Force platform requirements (if needed)\n";
        $content .= "composer update --ignore-platform-reqs\n";
        $content .= "```\n\n";

        $content .= "### Control Panel Issues\n";
        $content .= "- Clear browser cache and cookies\n";
        $content .= "- Try incognito/private browsing\n";
        $content .= "- Check browser console for JavaScript errors\n";
        $content .= "- Run `php artisan statamic:install --force`\n\n";

        $content .= "### Content/Stache Issues\n";
        $content .= "```bash\n";
        $content .= "# Nuclear cache clear\n";
        $content .= "php artisan cache:clear\n";
        $content .= "php artisan config:clear\n";
        $content .= "php artisan view:clear\n";
        $content .= "php artisan route:clear\n";
        $content .= "php artisan statamic:stache:clear\n";
        $content .= "php artisan statamic:stache:warm\n";
        $content .= "```\n\n";

        $content .= "### Addon Compatibility\n";
        $content .= "1. **Disable all addons** temporarily\n";
        $content .= "2. **Test core functionality**\n";
        $content .= "3. **Re-enable addons one by one**\n";
        $content .= "4. **Check addon documentation** for {$to} compatibility\n";
        $content .= "5. **Contact addon developers** if issues persist\n\n";

        $content .= "### Emergency Rollback\n";
        $content .= "If the upgrade fails completely:\n";
        $content .= "```bash\n";
        $content .= "# Restore from backup\n";
        $content .= "git reset --hard HEAD~1  # If using git\n";
        $content .= "# Or restore files and database from backup\n\n";
        $content .= "# Reinstall previous version\n";
        $content .= "composer require statamic/cms:{$from}\n";
        $content .= "composer install\n";
        $content .= "```\n\n";

        $content .= "### Getting Help\n";
        $content .= "- **Discord**: [Statamic Discord Community](https://statamic.com/discord)\n";
        $content .= "- **GitHub Issues**: Report bugs with detailed information\n";
        $content .= "- **Documentation**: Check official upgrade guides\n";
        $content .= "- **Professional Help**: Consider Statamic Partners for complex upgrades\n\n";

        return $content;
    }
}
