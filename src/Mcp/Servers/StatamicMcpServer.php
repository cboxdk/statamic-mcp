<?php

namespace Cboxdk\StatamicMcp\Mcp\Servers;

use Cboxdk\StatamicMcp\Mcp\Prompts\PageBuilderFieldsetsPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicBestPracticesPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicDataHandlingPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicTroubleshootingPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicUpgradePrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\StatamicWorkflowPrompt;
// Assets Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\CopyAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\CreateAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\DeleteAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\GetAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\ListAssetsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\MoveAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\RenameAssetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Assets\UpdateAssetTool;
// Blueprints Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CheckFieldDependenciesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\DeleteBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\DetectFieldConflictsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GenerateBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GetBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ScanBlueprintsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\TypesBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\UpdateBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ValidateBlueprintTool;
// Collections Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\CreateCollectionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\DeleteCollectionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\GetCollectionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\ListCollectionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\ReorderCollectionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Collections\UpdateCollectionTool;
// Development Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Development\AddonDiscoveryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\AddonsDevelopmentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\AnalyzeTemplatePerformanceTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\AntlersValidateTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\BladeHintsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\BladeLintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\ConsoleDevelopmentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\DetectUnusedTemplatesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\ExtractTemplateVariablesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\GenerateTypesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\ListTypeDefinitionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\SuggestTemplateOptimizationsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\TemplatesDevelopmentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Development\WidgetsDevelopmentTool;
// Entries Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\BatchEntriesOperationTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\CreateEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\CreateOrUpdateEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\DeleteEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\DuplicateEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\EntrySchedulingWorkflowTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\EntryVersioningTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\GetEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\ImportExportEntriesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\ListEntresTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\ManageEntryRelationshipsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\PublishEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\SearchEntresTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\UnpublishEntryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Entries\UpdateEntryTool;
// Fieldsets Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\CreateFieldsetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\DeleteFieldsetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\GetFieldsetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\ListFieldsetsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\ScanFieldsetsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\UpdateFieldsetTool;
// Field Types Tools
use Cboxdk\StatamicMcp\Mcp\Tools\FieldTypes\ListFieldTypesTool;
// Filters Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Filters\ListFiltersTool;
// Forms Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\CreateFormTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\DeleteFormTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\DeleteSubmissionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\ExportSubmissionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\GetFormTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\GetSubmissionTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\ListFormsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\ListSubmissionsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\SubmissionsStatsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Forms\UpdateFormTool;
// Globals Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\CreateGlobalSetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\DeleteGlobalSetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\GetGlobalSetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\GetGlobalTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\GetGlobalValuesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\ListGlobalSetsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\ListGlobalsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\ListGlobalValuesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\UpdateGlobalTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\UpdateGlobalValuesTool;
// Groups Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Groups\GetGroupTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Groups\ListGroupsTool;
// Modifiers Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Modifiers\ListModifiersTool;
// Navigations Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\CreateNavigationTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\DeleteNavigationTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\GetNavigationTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\ListNavigationContentTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\ListNavigationsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\UpdateNavigationTool;
// Permissions Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Permissions\ListPermissionsTool;
// Roles Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Roles\CreateRoleTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Roles\DeleteRoleTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Roles\GetRoleTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Roles\ListRolesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Roles\UpdateRoleTool;
// Scopes Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Scopes\ListScopesTool;
// Sites Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Sites\CreateSiteTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Sites\DeleteSiteTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Sites\GetSiteTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Sites\ListSitesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Sites\SwitchSiteTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Sites\UpdateSiteTool;
// System Tools
use Cboxdk\StatamicMcp\Mcp\Tools\System\CacheStatusTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\ClearCacheTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoverToolsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DocsSystemTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\GetToolSchemaTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\InfoSystemTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\LicenseManagementTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\PerformanceMonitorTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\PreferencesManagementTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\SearchIndexAnalyzerTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\SitesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\StacheManagementTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\SystemHealthCheckTool;
// Tags Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Tags\ListTagsTool;
// Taxonomies Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\AnalyzeTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\CreateTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\DeleteTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\GetTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\ListTaxonomyTermsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\ListTaxonomyTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\UpdateTaxonomyTool;
// Terms Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\CreateTermTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\DeleteTermTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\GetTermTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\ListTermsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Terms\UpdateTermTool;
// Users Tools
use Cboxdk\StatamicMcp\Mcp\Tools\Users\ActivateUserTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Users\CreateUserTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Users\DeleteUserTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Users\GetUserTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Users\ListUsersTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Users\UpdateUserTool;
use Laravel\Mcp\Server;

class StatamicMcpServer extends Server
{
    public int $defaultPaginationLength = 200;

    public int $maxPaginationLength = 200;

    /**
     * The tools that the server exposes.
     *
     * @var array<class-string>
     */
    public array $tools = [
        // Assets Tools (8)
        ListAssetsTool::class,
        GetAssetTool::class,
        CreateAssetTool::class,
        UpdateAssetTool::class,
        DeleteAssetTool::class,
        MoveAssetTool::class,
        CopyAssetTool::class,
        RenameAssetTool::class,

        // Blueprints Tools (11)
        ListBlueprintsTool::class,
        GetBlueprintTool::class,
        CreateBlueprintTool::class,
        UpdateBlueprintTool::class,
        DeleteBlueprintTool::class,
        ScanBlueprintsTool::class,
        TypesBlueprintTool::class,
        GenerateBlueprintTool::class,
        ValidateBlueprintTool::class,
        DetectFieldConflictsTool::class,
        CheckFieldDependenciesTool::class,

        // Collections Tools (6)
        ListCollectionsTool::class,
        GetCollectionTool::class,
        CreateCollectionTool::class,
        UpdateCollectionTool::class,
        DeleteCollectionTool::class,
        ReorderCollectionsTool::class,

        // Development Tools (10)
        TemplatesDevelopmentTool::class,
        AddonsDevelopmentTool::class,
        AddonDiscoveryTool::class,
        GenerateTypesTool::class,
        ListTypeDefinitionsTool::class,
        ConsoleDevelopmentTool::class,
        WidgetsDevelopmentTool::class,
        AntlersValidateTool::class,
        BladeHintsTool::class,
        BladeLintTool::class,

        // Template Analysis Tools (4)
        AnalyzeTemplatePerformanceTool::class,
        DetectUnusedTemplatesTool::class,
        ExtractTemplateVariablesTool::class,
        SuggestTemplateOptimizationsTool::class,

        // Entries Tools (15)
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

        // Fieldsets Tools (6)
        ListFieldsetsTool::class,
        GetFieldsetTool::class,
        CreateFieldsetTool::class,
        UpdateFieldsetTool::class,
        DeleteFieldsetTool::class,
        ScanFieldsetsTool::class,

        // Field Types Tools (1)
        ListFieldTypesTool::class,

        // Filters Tools (1)
        ListFiltersTool::class,

        // Forms Tools (10)
        ListFormsTool::class,
        GetFormTool::class,
        CreateFormTool::class,
        UpdateFormTool::class,
        DeleteFormTool::class,
        ListSubmissionsTool::class,
        GetSubmissionTool::class,
        DeleteSubmissionTool::class,
        ExportSubmissionsTool::class,
        SubmissionsStatsTool::class,

        // Globals Tools (10)
        ListGlobalsTool::class,
        GetGlobalTool::class,
        UpdateGlobalTool::class,
        CreateGlobalSetTool::class,
        DeleteGlobalSetTool::class,
        GetGlobalSetTool::class,
        ListGlobalSetsTool::class,
        GetGlobalValuesTool::class,
        ListGlobalValuesTool::class,
        UpdateGlobalValuesTool::class,

        // Groups Tools (2)
        ListGroupsTool::class,
        GetGroupTool::class,

        // Modifiers Tools (1)
        ListModifiersTool::class,

        // Navigations Tools (6)
        ListNavigationsTool::class,
        GetNavigationTool::class,
        CreateNavigationTool::class,
        UpdateNavigationTool::class,
        DeleteNavigationTool::class,
        ListNavigationContentTool::class,

        // Permissions Tools (1)
        ListPermissionsTool::class,

        // Roles Tools (5)
        ListRolesTool::class,
        GetRoleTool::class,
        CreateRoleTool::class,
        UpdateRoleTool::class,
        DeleteRoleTool::class,

        // Scopes Tools (1)
        ListScopesTool::class,

        // Sites Tools (6)
        ListSitesTool::class,
        GetSiteTool::class,
        CreateSiteTool::class,
        UpdateSiteTool::class,
        DeleteSiteTool::class,
        SwitchSiteTool::class,

        // System Tools (13)
        InfoSystemTool::class,
        ClearCacheTool::class,
        CacheStatusTool::class,
        DocsSystemTool::class,
        SystemHealthCheckTool::class,
        LicenseManagementTool::class,
        PerformanceMonitorTool::class,
        PreferencesManagementTool::class,
        StacheManagementTool::class,
        SearchIndexAnalyzerTool::class,
        SitesTool::class,
        DiscoverToolsTool::class,
        GetToolSchemaTool::class,

        // Tags Tools (1)
        ListTagsTool::class,

        // Taxonomies Tools (7)
        ListTaxonomyTool::class,
        GetTaxonomyTool::class,
        CreateTaxonomyTool::class,
        UpdateTaxonomyTool::class,
        DeleteTaxonomyTool::class,
        AnalyzeTaxonomyTool::class,
        ListTaxonomyTermsTool::class,

        // Terms Tools (5)
        ListTermsTool::class,
        GetTermTool::class,
        CreateTermTool::class,
        UpdateTermTool::class,
        DeleteTermTool::class,

        // Users Tools (6)
        ListUsersTool::class,
        GetUserTool::class,
        CreateUserTool::class,
        UpdateUserTool::class,
        DeleteUserTool::class,
        ActivateUserTool::class,
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
        return 'Comprehensive MCP server for Statamic development with 136 tools covering all aspects of CMS management, content operations, template development, and system administration';
    }

    /**
     * Get the server version.
     */
    public function version(): string
    {
        return '0.1.0-alpha';
    }
}
