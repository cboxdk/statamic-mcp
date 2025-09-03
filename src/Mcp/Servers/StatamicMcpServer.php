<?php

namespace Cboxdk\StatamicMcp\Mcp\Servers;

use Cboxdk\StatamicMcp\Mcp\Prompts\PageBuilderFieldsetsPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicBestPracticesPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicDataHandlingPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicTroubleshootingPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicUpgradePrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicWorkflowPrompt;
// Structure Tools - Schema & Configuration
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\CopyAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\CreateAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\DeleteAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\GetAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\ListAssetsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\MoveAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\RenameAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\UpdateAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\DeleteBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GetBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\UpdateBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\CreateCollectionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\DeleteCollectionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\GetCollectionTool;
// New single-purpose tools
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\ListCollectionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\ReorderCollectionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\UpdateCollectionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\AddonDiscoveryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\AddonsDevelopmentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\ConsoleDevelopmentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\GenerateTypesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\ListTypeDefinitionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\TemplatesDevelopmentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\WidgetsDevelopmentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\BatchEntriesOperationTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\CreateEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\CreateOrUpdateEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\DeleteEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\DuplicateEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\EntrySchedulingWorkflowTool;
// Entry Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\EntryVersioningTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\GetEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\ImportExportEntriesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\ListEntresTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\ManageEntryRelationshipsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\PublishEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\SearchEntresTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\UnpublishEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\UpdateEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\CreateFieldsetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\DeleteFieldsetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\GetFieldsetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\ListFieldsetsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\UpdateFieldsetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\FieldTypes\ListFieldTypesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Filters\ListFiltersTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\CreateFormTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\DeleteFormTool;
// Blocks Tools - Template Building Blocks
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\DeleteSubmissionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\ExportSubmissionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\GetFormTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\GetSubmissionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\ListFormsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\ListSubmissionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\SubmissionsStatsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\UpdateFormTool;
// Fieldset Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\GetGlobalTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\ListGlobalsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\UpdateGlobalTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Groups\GetGroupTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Groups\ListGroupsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Modifiers\ListModifiersTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\CreateNavigationTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\DeleteNavigationTool;
// Asset Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\GetNavigationTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\ListNavigationContentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\ListNavigationsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\UpdateNavigationTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Permissions\ListPermissionsTool;
// Group Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Scopes\ListScopesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\CacheStatusTool;
// Permission Tools
use Cboxdk\StatamicMcp\Mcp\Tools\System\ClearCacheTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DocsSystemTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\InfoSystemTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\LicenseManagementTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\PreferencesManagementTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\SearchIndexAnalyzerTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\StacheManagementTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Tags\ListTagsTool;
// Taxonomy Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\AnalyzeTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\CreateTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\DeleteTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\GetTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\ListTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\UpdateTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\CreateTermTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\DeleteTermTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\GetTermTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\ListTermsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\UpdateTermTool;
// Development Tools - Additional Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Users\GetUserTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Users\ListUsersTool;
use Laravel\Mcp\Server;

class StatamicMcpServer extends Server
{
    /**
     * The tools that the server exposes.
     *
     * @var array<class-string>
     */
    public array $tools = [
        // Blueprint Tools - Single Purpose Commands
        ListBlueprintsTool::class,
        GetBlueprintTool::class,
        CreateBlueprintTool::class,
        UpdateBlueprintTool::class,
        DeleteBlueprintTool::class,

        // Collections Tools - Single Purpose Commands
        ListCollectionsTool::class,
        GetCollectionTool::class,
        CreateCollectionTool::class,
        UpdateCollectionTool::class,
        DeleteCollectionTool::class,
        ReorderCollectionsTool::class,

        // Structure Tools - Schema & Configuration
        // Fieldset Tools
        ListFieldsetsTool::class,
        GetFieldsetTool::class,
        CreateFieldsetTool::class,
        UpdateFieldsetTool::class,
        DeleteFieldsetTool::class,
        // Taxonomy Tools
        ListTaxonomyTool::class,
        GetTaxonomyTool::class,
        CreateTaxonomyTool::class,
        UpdateTaxonomyTool::class,
        DeleteTaxonomyTool::class,
        AnalyzeTaxonomyTool::class,
        // Navigation Tools
        ListNavigationsTool::class,
        GetNavigationTool::class,
        CreateNavigationTool::class,
        UpdateNavigationTool::class,
        DeleteNavigationTool::class,
        // Form Tools
        ListFormsTool::class,
        GetFormTool::class,
        CreateFormTool::class,
        UpdateFormTool::class,
        DeleteFormTool::class,
        // Form Submission Tools
        ListSubmissionsTool::class,
        GetSubmissionTool::class,
        DeleteSubmissionTool::class,
        ExportSubmissionsTool::class,
        SubmissionsStatsTool::class,
        // Asset Tools
        ListAssetsTool::class,
        GetAssetTool::class,
        CreateAssetTool::class,
        UpdateAssetTool::class,
        DeleteAssetTool::class,
        MoveAssetTool::class,
        CopyAssetTool::class,
        RenameAssetTool::class,

        // User Tools
        ListUsersTool::class,
        GetUserTool::class,

        // Group Tools
        ListGroupsTool::class,
        GetGroupTool::class,

        // Permission Tools
        ListPermissionsTool::class,

        // Content Tools - Actual Data & Entries
        // Entry Tools
        ListEntresTool::class,
        SearchEntresTool::class,
        GetEntryTool::class,
        CreateEntryTool::class,
        CreateOrUpdateEntryTool::class,
        UpdateEntryTool::class,
        DuplicateEntryTool::class,
        BatchEntriesOperationTool::class,
        ImportExportEntriesTool::class,
        ManageEntryRelationshipsTool::class,
        EntryVersioningTool::class,
        EntrySchedulingWorkflowTool::class,
        DeleteEntryTool::class,
        PublishEntryTool::class,
        UnpublishEntryTool::class,
        // Terms Tools
        ListTermsTool::class,
        GetTermTool::class,
        CreateTermTool::class,
        UpdateTermTool::class,
        DeleteTermTool::class,

        // Globals Tools
        ListGlobalsTool::class,
        GetGlobalTool::class,
        UpdateGlobalTool::class,

        // Navigation Content Tools
        ListNavigationContentTool::class,

        // Blocks Tools - Template Building Blocks
        ListTagsTool::class,
        ListModifiersTool::class,
        ListFieldTypesTool::class,
        ListScopesTool::class,
        ListFiltersTool::class,

        // Development Tools - Developer Experience
        TemplatesDevelopmentTool::class,
        AddonsDevelopmentTool::class,
        GenerateTypesTool::class,
        ListTypeDefinitionsTool::class,
        ConsoleDevelopmentTool::class,
        WidgetsDevelopmentTool::class,
        AddonDiscoveryTool::class,

        // System Tools - System Management
        InfoSystemTool::class,
        ClearCacheTool::class,
        CacheStatusTool::class,
        DocsSystemTool::class,
        LicenseManagementTool::class,
        PreferencesManagementTool::class,
        StacheManagementTool::class,
        SearchIndexAnalyzerTool::class,
    ];

    /**
     * The prompts that the server exposes.
     *
     * @var array<class-string>
     */
    public array $prompts = [
        StatamicBestPracticesPrompt::class,
        PageBuilderFieldsetsPrompt::class,
        StatamicWorkflowPrompt::class,
        StatamicTroubleshootingPrompt::class,
        StatamicDataHandlingPrompt::class,
        StatamicUpgradePrompt::class,
    ];

    /**
     * Get the server name.
     */
    public function name(): string
    {
        return 'Statamic MCP Server';
    }

    /**
     * Get the server description.
     */
    public function description(): string
    {
        return 'Comprehensive MCP server for Statamic development with content management, cache control, blueprint analysis, template validation, and enhanced developer experience';
    }

    /**
     * Get the server version.
     */
    public function version(): string
    {
        return '1.0.0';
    }
}
