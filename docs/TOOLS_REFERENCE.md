# MCP Tools Reference

Complete reference for all **135 MCP tools** provided by the Statamic MCP Server.

This comprehensive MCP server provides complete CRUD operations and advanced management capabilities for every aspect of Statamic CMS, organized into 22 categories.

## Quick Navigation

- [Assets Tools (8)](#assets-tools) - File and media management
- [Blueprints Tools (11)](#blueprints-tools) - Schema definition and validation
- [Collections Tools (6)](#collections-tools) - Content structure management
- [Development Tools (10)](#development-tools) - Developer experience and tooling
- [Entries Tools (15)](#entries-tools) - Content CRUD and workflows
- [Fieldsets Tools (5)](#fieldsets-tools) - Reusable field groups
- [Field Types Tools (1)](#field-types-tools) - Available field types
- [Filters Tools (1)](#filters-tools) - Query scopes and filters
- [Forms Tools (10)](#forms-tools) - Form management and submissions
- [Globals Tools (10)](#globals-tools) - Site-wide variables
- [Groups Tools (2)](#groups-tools) - User groups management
- [Modifiers Tools (1)](#modifiers-tools) - Template variable modifiers
- [Navigations Tools (6)](#navigations-tools) - Menu and navigation structure
- [Permissions Tools (1)](#permissions-tools) - Access control
- [Roles Tools (5)](#roles-tools) - User role management
- [Scopes Tools (1)](#scopes-tools) - Query scopes
- [Sites Tools (6)](#sites-tools) - Multi-site management
- [System Tools (13)](#system-tools) - System administration
- [Tags Tools (1)](#tags-tools) - Template tags
- [Taxonomies Tools (7)](#taxonomies-tools) - Taxonomy management
- [Templates Tools (4)](#templates-tools) - Template analysis
- [Terms Tools (5)](#terms-tools) - Taxonomy term CRUD
- [Users Tools (6)](#users-tools) - User management

## Common Parameters

Most tools support these optional parameters:

- `limit` (integer) - Limit results (default: 50, max: 100)
- `offset` (integer) - Offset for pagination (default: 0)
- `include_meta` (boolean) - Include metadata and configuration (default: true)
- `filter` (string) - Filter results by name/handle

## Assets Tools

File and media management operations.

### `statamic.assets.copy`
Copy an asset to a new location within the same container.

**Parameters:**
- `container` (string, required) - Asset container handle
- `path` (string, required) - Source asset path
- `destination` (string, required) - Destination path

### `statamic.assets.create`
Upload and create a new asset.

**Parameters:**
- `container` (string, required) - Asset container handle
- `path` (string, required) - Asset path
- `file_data` (string, required) - Base64 encoded file data
- `meta` (object, optional) - Asset metadata

### `statamic.assets.delete`
Delete an asset permanently.

**Parameters:**
- `container` (string, required) - Asset container handle
- `path` (string, required) - Asset path

### `statamic.assets.get`
Retrieve detailed information about a specific asset.

**Parameters:**
- `container` (string, required) - Asset container handle
- `path` (string, required) - Asset path
- `include_meta` (boolean, optional) - Include metadata

### `statamic.assets.list`
List all assets in a container with optional filtering.

**Parameters:**
- `container` (string, optional) - Asset container handle (all if omitted)
- `folder` (string, optional) - Filter by folder path
- `type` (string, optional) - Filter by file type (image, video, document)
- `limit` (integer, optional) - Limit results
- `include_meta` (boolean, optional) - Include metadata

### `statamic.assets.move`
Move an asset to a different location or container.

**Parameters:**
- `container` (string, required) - Source container handle
- `path` (string, required) - Source asset path
- `destination_container` (string, required) - Destination container
- `destination_path` (string, required) - Destination path

### `statamic.assets.rename`
Rename an asset within the same container.

**Parameters:**
- `container` (string, required) - Asset container handle
- `path` (string, required) - Current asset path
- `new_name` (string, required) - New filename

### `statamic.assets.update`
Update asset metadata and properties.

**Parameters:**
- `container` (string, required) - Asset container handle
- `path` (string, required) - Asset path
- `meta` (object, required) - Updated metadata
- `alt` (string, optional) - Alt text for images

## Blueprints Tools

Schema definition, validation, and type generation.

### `statamic.blueprints.create`
Create a new blueprint with field definitions.

**Parameters:**
- `handle` (string, required) - Blueprint handle
- `title` (string, required) - Blueprint title
- `namespace` (string, required) - Blueprint namespace (collections, taxonomies, assets, etc.)
- `fields` (array, required) - Field definitions
- `tabs` (array, optional) - Tab organization

### `statamic.blueprints.delete`
Delete a blueprint permanently.

**Parameters:**
- `handle` (string, required) - Blueprint handle
- `namespace` (string, required) - Blueprint namespace

### `statamic.blueprints.field-conflicts`
Analyze field conflicts between related blueprints.

**Parameters:**
- `namespace` (string, optional) - Specific namespace to analyze
- `blueprint` (string, optional) - Specific blueprint to analyze
- `severity` (string, optional) - Minimum conflict severity (warning, error)

### `statamic.blueprints.field-dependencies`
Analyze field dependencies and relationships.

**Parameters:**
- `blueprint` (string, required) - Blueprint handle
- `namespace` (string, required) - Blueprint namespace
- `include_reverse` (boolean, optional) - Include reverse dependencies

### `statamic.blueprints.generate`
Generate blueprint from existing content or schema.

**Parameters:**
- `source` (string, required) - Source type (entries, json, yaml)
- `source_data` (mixed, required) - Source data
- `handle` (string, required) - Generated blueprint handle
- `namespace` (string, required) - Blueprint namespace

### `statamic.blueprints.get`
Retrieve complete blueprint definition with all field details.

**Parameters:**
- `handle` (string, required) - Blueprint handle
- `namespace` (string, required) - Blueprint namespace
- `include_field_types` (boolean, optional) - Include field type details
- `include_validation` (boolean, optional) - Include validation rules

### `statamic.blueprints.list`
List all blueprints with optional filtering and details.

**Parameters:**
- `namespace` (string, optional) - Filter by namespace
- `include_fields` (boolean, optional) - Include field information
- `include_counts` (boolean, optional) - Include usage counts
- `filter` (string, optional) - Filter by blueprint name

### `statamic.blueprints.scan`
Comprehensive blueprint analysis with relationships and validation.

**Parameters:**
- `include_relationships` (boolean, optional) - Include relationship mapping
- `include_validation` (boolean, optional) - Include validation rules
- `include_field_details` (boolean, optional) - Include detailed field information
- `blueprint_filter` (string, optional) - Filter by blueprint name
- `namespace` (string, optional) - Filter by namespace

### `statamic.blueprints.types`
Generate TypeScript, PHP, or JSON Schema types from blueprints.

**Parameters:**
- `blueprints` (string, optional) - Specific blueprint name
- `namespace` (string, optional) - Filter by namespace
- `format` (string, required) - Output format: "ts", "php", "json"
- `include_validation` (boolean, optional) - Include validation rules
- `include_relationships` (boolean, optional) - Include relationship types

### `statamic.blueprints.update`
Update existing blueprint with field changes.

**Parameters:**
- `handle` (string, required) - Blueprint handle
- `namespace` (string, required) - Blueprint namespace
- `fields` (array, optional) - Updated field definitions
- `title` (string, optional) - Updated title
- `tabs` (array, optional) - Updated tab organization

### `statamic.blueprints.validate`
Validate blueprint structure and field definitions.

**Parameters:**
- `handle` (string, required) - Blueprint handle
- `namespace` (string, required) - Blueprint namespace
- `strict` (boolean, optional) - Enable strict validation
- `check_references` (boolean, optional) - Validate field references

## Collections Tools

Content structure and collection management.

### `statamic.collections.create`
Create a new collection with configuration.

**Parameters:**
- `handle` (string, required) - Collection handle
- `title` (string, required) - Collection title
- `blueprint` (string, optional) - Default blueprint handle
- `route` (string, optional) - URL route pattern
- `sites` (array, optional) - Available sites
- `settings` (object, optional) - Collection settings

### `statamic.collections.delete`
Delete a collection and optionally its entries.

**Parameters:**
- `handle` (string, required) - Collection handle
- `delete_entries` (boolean, optional) - Delete all entries (default: false)
- `force` (boolean, optional) - Force deletion without confirmation

### `statamic.collections.get`
Retrieve detailed collection configuration.

**Parameters:**
- `handle` (string, required) - Collection handle
- `include_blueprints` (boolean, optional) - Include associated blueprints
- `include_counts` (boolean, optional) - Include entry counts

### `statamic.collections.list`
List all collections with configuration details.

**Parameters:**
- `include_blueprints` (boolean, optional) - Include blueprint information
- `include_counts` (boolean, optional) - Include entry counts
- `filter` (string, optional) - Filter by collection name

### `statamic.collections.reorder`
Reorder collections in the control panel.

**Parameters:**
- `order` (array, required) - Array of collection handles in desired order

### `statamic.collections.update`
Update collection configuration and settings.

**Parameters:**
- `handle` (string, required) - Collection handle
- `title` (string, optional) - Updated title
- `blueprint` (string, optional) - Default blueprint
- `route` (string, optional) - URL route pattern
- `sites` (array, optional) - Available sites
- `settings` (object, optional) - Collection settings

## Development Tools

Developer experience, validation, and tooling.

### `statamic.development.addon-discovery`
Discover and analyze addon capabilities.

**Parameters:**
- `addon_name` (string, optional) - Specific addon to analyze
- `include_tags` (boolean, optional) - Include addon tags
- `include_modifiers` (boolean, optional) - Include addon modifiers
- `include_fieldtypes` (boolean, optional) - Include field types

### `statamic.development.addons`
Comprehensive addon management and analysis.

**Parameters:**
- `include_tags` (boolean, optional) - Include addon tags
- `include_modifiers` (boolean, optional) - Include addon modifiers
- `include_fieldtypes` (boolean, optional) - Include field types
- `include_documentation` (boolean, optional) - Include documentation links
- `addon_filter` (string, optional) - Filter by addon name

### `statamic.development.antlers-validate`
Validate Antlers template syntax and variables.

**Parameters:**
- `template` (string, required) - Template content to validate
- `blueprint` (string, optional) - Blueprint context for validation
- `strict_mode` (boolean, optional) - Enable strict validation
- `include_suggestions` (boolean, optional) - Include improvement suggestions

### `statamic.development.blade-hints`
Get Statamic-specific Blade component suggestions and hints.

**Parameters:**
- `blueprint` (string, optional) - Blueprint context
- `template_content` (string, optional) - Current template content
- `include_components` (boolean, optional) - Include component suggestions
- `include_best_practices` (boolean, optional) - Include best practices

### `statamic.development.blade-lint`
Comprehensive Blade template linting with Statamic policies.

**Parameters:**
- `template` (string, required) - Blade template content
- `file_path` (string, optional) - File path for context
- `auto_fix` (boolean, optional) - Provide auto-fix suggestions
- `policy` (string, optional) - Linting policy level (strict, recommended, basic)
- `rules` (array, optional) - Specific rules to check

### `statamic.development.console`
Execute Artisan commands and get system information.

**Parameters:**
- `command` (string, required) - Artisan command to execute
- `parameters` (array, optional) - Command parameters
- `options` (array, optional) - Command options

### `statamic.development.templates`
Template analysis and optimization suggestions.

**Parameters:**
- `template_path` (string, optional) - Specific template to analyze
- `include_performance` (boolean, optional) - Include performance analysis
- `include_accessibility` (boolean, optional) - Include accessibility checks
- `template_type` (string, optional) - Template type (antlers, blade)

### `statamic.development.types.generate`
Generate type definitions from Statamic schemas.

**Parameters:**
- `format` (string, required) - Output format (typescript, php, json)
- `blueprints` (array, optional) - Specific blueprints to process
- `output_path` (string, optional) - Output file path
- `namespace` (string, optional) - Type namespace

### `statamic.development.types.list`
List available type generation options and formats.

**Parameters:**
- `format` (string, optional) - Filter by output format
- `include_examples` (boolean, optional) - Include generation examples

### `statamic.development.widgets`
Manage and analyze control panel widgets.

**Parameters:**
- `widget_type` (string, optional) - Specific widget type
- `include_configuration` (boolean, optional) - Include widget configuration

## Entries Tools

Content CRUD operations, workflows, and management.

### `statamic.entries.batch_operation`
Perform batch operations on multiple entries.

**Parameters:**
- `collection` (string, required) - Collection handle
- `operation` (string, required) - Operation type (publish, unpublish, delete, update)
- `entries` (array, required) - Array of entry IDs
- `data` (object, optional) - Data for update operations

### `statamic.entries.create`
Create a new entry in a collection.

**Parameters:**
- `collection` (string, required) - Collection handle
- `data` (object, required) - Entry data
- `site` (string, optional) - Site handle
- `blueprint` (string, optional) - Blueprint handle
- `published` (boolean, optional) - Publish immediately

### `statamic.entries.create_or_update`
Create a new entry or update existing one by ID or slug.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id_or_slug` (string, required) - Entry ID or slug
- `data` (object, required) - Entry data
- `site` (string, optional) - Site handle
- `published` (boolean, optional) - Published status

### `statamic.entries.delete`
Delete an entry permanently.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Entry ID
- `site` (string, optional) - Site handle

### `statamic.entries.duplicate`
Duplicate an existing entry.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Source entry ID
- `site` (string, optional) - Site handle
- `new_data` (object, optional) - Modified data for duplicate

### `statamic.entries.get`
Retrieve a specific entry with all data.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Entry ID
- `site` (string, optional) - Site handle
- `include_meta` (boolean, optional) - Include metadata

### `statamic.entries.import_export`
Import or export entries in various formats.

**Parameters:**
- `operation` (string, required) - Operation type (import, export)
- `collection` (string, required) - Collection handle
- `format` (string, required) - Data format (csv, json, yaml)
- `data` (mixed, optional) - Import data
- `file_path` (string, optional) - Export file path

### `statamic.entries.list`
List entries with filtering and pagination.

**Parameters:**
- `collection` (string, optional) - Collection handle (all if omitted)
- `site` (string, optional) - Site handle
- `status` (string, optional) - Filter by status (published, draft)
- `limit` (integer, optional) - Limit results
- `offset` (integer, optional) - Pagination offset
- `sort` (string, optional) - Sort field
- `order` (string, optional) - Sort order (asc, desc)

### `statamic.entries.manage_relationships`
Manage entry relationships and references.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Entry ID
- `relationships` (object, required) - Relationship data
- `operation` (string, required) - Operation (add, remove, update)

### `statamic.entries.publish`
Publish a draft entry.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Entry ID
- `site` (string, optional) - Site handle

### `statamic.entries.scheduling_workflow`
Manage entry scheduling and workflow.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Entry ID
- `schedule_data` (object, required) - Scheduling information
- `workflow_stage` (string, optional) - Workflow stage

### `statamic.entries.search`
Search entries across collections with advanced filtering.

**Parameters:**
- `query` (string, required) - Search query
- `collections` (array, optional) - Collections to search
- `fields` (array, optional) - Fields to search
- `limit` (integer, optional) - Limit results
- `site` (string, optional) - Site handle

### `statamic.entries.unpublish`
Unpublish an entry (convert to draft).

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Entry ID
- `site` (string, optional) - Site handle

### `statamic.entries.update`
Update an existing entry.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Entry ID
- `data` (object, required) - Updated entry data
- `site` (string, optional) - Site handle
- `published` (boolean, optional) - Published status

### `statamic.entries.versioning`
Manage entry versions and revisions.

**Parameters:**
- `collection` (string, required) - Collection handle
- `id` (string, required) - Entry ID
- `operation` (string, required) - Operation (list, restore, compare)
- `version` (string, optional) - Specific version ID

## Fieldsets Tools

Reusable field group management.

### `statamic.fieldsets.create`
Create a new fieldset with field definitions.

**Parameters:**
- `handle` (string, required) - Fieldset handle
- `title` (string, required) - Fieldset title
- `fields` (array, required) - Field definitions

### `statamic.fieldsets.delete`
Delete a fieldset permanently.

**Parameters:**
- `handle` (string, required) - Fieldset handle

### `statamic.fieldsets.get`
Retrieve complete fieldset definition.

**Parameters:**
- `handle` (string, required) - Fieldset handle
- `include_usage` (boolean, optional) - Include usage information

### `statamic.fieldsets.list`
List all available fieldsets.

**Parameters:**
- `include_fields` (boolean, optional) - Include field information
- `include_usage` (boolean, optional) - Include usage counts

### `statamic.fieldsets.update`
Update existing fieldset definition.

**Parameters:**
- `handle` (string, required) - Fieldset handle
- `title` (string, optional) - Updated title
- `fields` (array, optional) - Updated field definitions

## Field Types Tools

Available field type discovery and configuration.

### `statamic.fieldtypes.list`
List all available field types with configuration examples.

**Parameters:**
- `category` (string, optional) - Filter by category (text, media, relationships, etc.)
- `type` (string, optional) - Get specific field type details
- `include_examples` (boolean, optional) - Include configuration examples
- `include_validation` (boolean, optional) - Include validation options

## Filters Tools

Query scope and filter management.

### `statamic.filters.list`
List available query filters and scopes.

**Parameters:**
- `type` (string, optional) - Filter type (entries, terms, users)
- `include_examples` (boolean, optional) - Include usage examples

## Forms Tools

Form management and submission handling.

### `statamic.forms.create`
Create a new form with field definitions.

**Parameters:**
- `handle` (string, required) - Form handle
- `title` (string, required) - Form title
- `fields` (array, required) - Form field definitions
- `email` (object, optional) - Email configuration
- `store` (boolean, optional) - Store submissions

### `statamic.forms.delete`
Delete a form and optionally its submissions.

**Parameters:**
- `handle` (string, required) - Form handle
- `delete_submissions` (boolean, optional) - Delete submissions (default: false)

### `statamic.forms.get`
Retrieve complete form configuration.

**Parameters:**
- `handle` (string, required) - Form handle
- `include_submissions_count` (boolean, optional) - Include submission count

### `statamic.forms.list`
List all forms with configuration details.

**Parameters:**
- `include_submissions_count` (boolean, optional) - Include submission counts

### `statamic.forms.submissions.delete`
Delete form submissions.

**Parameters:**
- `form` (string, required) - Form handle
- `id` (string, optional) - Specific submission ID
- `before_date` (string, optional) - Delete submissions before date

### `statamic.forms.submissions.export`
Export form submissions in various formats.

**Parameters:**
- `form` (string, required) - Form handle
- `format` (string, required) - Export format (csv, json, yaml)
- `date_range` (object, optional) - Date range filter
- `fields` (array, optional) - Specific fields to export

### `statamic.forms.submissions.get`
Retrieve specific form submission.

**Parameters:**
- `form` (string, required) - Form handle
- `id` (string, required) - Submission ID

### `statamic.forms.submissions.list`
List form submissions with filtering and pagination.

**Parameters:**
- `form` (string, required) - Form handle
- `limit` (integer, optional) - Limit results
- `offset` (integer, optional) - Pagination offset
- `date_range` (object, optional) - Date range filter

### `statamic.forms.submissions.stats`
Get submission statistics and analytics.

**Parameters:**
- `form` (string, required) - Form handle
- `period` (string, optional) - Time period (day, week, month, year)
- `include_fields` (boolean, optional) - Include field statistics

### `statamic.forms.update`
Update form configuration and fields.

**Parameters:**
- `handle` (string, required) - Form handle
- `title` (string, optional) - Updated title
- `fields` (array, optional) - Updated field definitions
- `email` (object, optional) - Email configuration
- `store` (boolean, optional) - Store submissions

## Globals Tools

Site-wide variables and global sets management.

### `statamic.globals.get`
Retrieve global set values for specific site.

**Parameters:**
- `handle` (string, required) - Global set handle
- `site` (string, optional) - Site handle
- `include_meta` (boolean, optional) - Include metadata

### `statamic.globals.list`
List all global sets with values.

**Parameters:**
- `site` (string, optional) - Site handle
- `include_values` (boolean, optional) - Include current values

### `statamic.globals.sets.create`
Create a new global set.

**Parameters:**
- `handle` (string, required) - Global set handle
- `title` (string, required) - Global set title
- `blueprint` (string, optional) - Blueprint handle

### `statamic.globals.sets.delete`
Delete a global set permanently.

**Parameters:**
- `handle` (string, required) - Global set handle

### `statamic.globals.sets.get`
Retrieve global set configuration.

**Parameters:**
- `handle` (string, required) - Global set handle
- `include_blueprint` (boolean, optional) - Include blueprint information

### `statamic.globals.sets.list`
List all global set configurations.

**Parameters:**
- `include_blueprints` (boolean, optional) - Include blueprint information

### `statamic.globals.update`
Update global set values for specific site.

**Parameters:**
- `handle` (string, required) - Global set handle
- `data` (object, required) - Updated values
- `site` (string, optional) - Site handle

### `statamic.globals.values.get`
Retrieve global values across all sites.

**Parameters:**
- `handle` (string, required) - Global set handle
- `key` (string, optional) - Specific value key

### `statamic.globals.values.list`
List global values with site variations.

**Parameters:**
- `handle` (string, optional) - Filter by global set
- `key` (string, optional) - Filter by value key

### `statamic.globals.values.update`
Update specific global values across sites.

**Parameters:**
- `handle` (string, required) - Global set handle
- `key` (string, required) - Value key
- `value` (mixed, required) - New value
- `sites` (array, optional) - Specific sites to update

## Groups Tools

User group management.

### `statamic.groups.get`
Retrieve specific user group details.

**Parameters:**
- `handle` (string, required) - Group handle
- `include_users` (boolean, optional) - Include group members

### `statamic.groups.list`
List all user groups with member counts.

**Parameters:**
- `include_users` (boolean, optional) - Include member lists
- `include_permissions` (boolean, optional) - Include permissions

## Modifiers Tools

Template variable modifier discovery.

### `statamic.modifiers.list`
List all available template modifiers with examples.

**Parameters:**
- `category` (string, optional) - Filter by category
- `include_examples` (boolean, optional) - Include usage examples
- `search` (string, optional) - Search modifier names

## Navigations Tools

Menu and navigation structure management.

### `statamic.navigations.create`
Create a new navigation structure.

**Parameters:**
- `handle` (string, required) - Navigation handle
- `title` (string, required) - Navigation title
- `max_depth` (integer, optional) - Maximum nesting depth
- `collections` (array, optional) - Associated collections

### `statamic.navigations.delete`
Delete a navigation structure.

**Parameters:**
- `handle` (string, required) - Navigation handle

### `statamic.navigations.get`
Retrieve navigation structure and configuration.

**Parameters:**
- `handle` (string, required) - Navigation handle
- `site` (string, optional) - Site handle
- `include_content` (boolean, optional) - Include navigation tree

### `statamic.navigations.list`
List all navigation structures.

**Parameters:**
- `include_trees` (boolean, optional) - Include navigation trees
- `site` (string, optional) - Site handle

### `statamic.navigations.list_content`
List navigation content and structure.

**Parameters:**
- `handle` (string, required) - Navigation handle
- `site` (string, optional) - Site handle
- `max_depth` (integer, optional) - Maximum depth to retrieve

### `statamic.navigations.update`
Update navigation structure and content.

**Parameters:**
- `handle` (string, required) - Navigation handle
- `tree` (array, required) - Updated navigation tree
- `site` (string, optional) - Site handle

## Permissions Tools

Access control and permission management.

### `statamic.permissions.list`
List all available permissions and their descriptions.

**Parameters:**
- `group` (string, optional) - Filter by permission group
- `include_descriptions` (boolean, optional) - Include detailed descriptions

## Roles Tools

User role management and permissions.

### `statamic.roles.create`
Create a new user role with permissions.

**Parameters:**
- `handle` (string, required) - Role handle
- `title` (string, required) - Role title
- `permissions` (array, required) - Role permissions

### `statamic.roles.delete`
Delete a user role.

**Parameters:**
- `handle` (string, required) - Role handle

### `statamic.roles.get`
Retrieve specific role details and permissions.

**Parameters:**
- `handle` (string, required) - Role handle
- `include_users` (boolean, optional) - Include users with this role

### `statamic.roles.list`
List all user roles with permissions.

**Parameters:**
- `include_permissions` (boolean, optional) - Include permission details
- `include_user_counts` (boolean, optional) - Include user counts

### `statamic.roles.update`
Update role permissions and details.

**Parameters:**
- `handle` (string, required) - Role handle
- `title` (string, optional) - Updated title
- `permissions` (array, optional) - Updated permissions

## Scopes Tools

Query scope management.

### `statamic.scopes.list`
List available query scopes for filtering.

**Parameters:**
- `type` (string, optional) - Scope type (entries, terms, users)
- `include_parameters` (boolean, optional) - Include scope parameters

## Sites Tools

Multi-site configuration and management.

### `statamic.sites.create`
Create a new site configuration.

**Parameters:**
- `handle` (string, required) - Site handle
- `name` (string, required) - Site name
- `url` (string, required) - Site URL
- `locale` (string, required) - Site locale
- `attributes` (object, optional) - Additional attributes

### `statamic.sites.delete`
Delete a site configuration.

**Parameters:**
- `handle` (string, required) - Site handle

### `statamic.sites.get`
Retrieve specific site configuration.

**Parameters:**
- `handle` (string, required) - Site handle
- `include_stats` (boolean, optional) - Include site statistics

### `statamic.sites.list`
List all site configurations.

**Parameters:**
- `include_stats` (boolean, optional) - Include statistics for each site

### `statamic.sites.switch`
Switch active site context.

**Parameters:**
- `handle` (string, required) - Site handle to switch to

### `statamic.sites.update`
Update site configuration and settings.

**Parameters:**
- `handle` (string, required) - Site handle
- `name` (string, optional) - Updated name
- `url` (string, optional) - Updated URL
- `locale` (string, optional) - Updated locale
- `attributes` (object, optional) - Updated attributes

## System Tools

System administration, health checks, and management.

### `statamic.system.cache.clear`
Clear various types of cache with selective options.

**Parameters:**
- `types` (array, optional) - Cache types to clear (stache, static, views, application)
- `tags` (array, optional) - Specific cache tags to clear
- `force` (boolean, optional) - Force clear without confirmation

### `statamic.system.cache.status`
Get comprehensive cache status and statistics.

**Parameters:**
- `include_sizes` (boolean, optional) - Include cache size information
- `include_stats` (boolean, optional) - Include cache statistics

### `statamic.system.docs`
Search and retrieve Statamic documentation.

**Parameters:**
- `query` (string, required) - Search query
- `section` (string, optional) - Filter by section (core, fieldtypes, tags, templating)
- `include_content` (boolean, optional) - Include full content
- `limit` (integer, optional) - Limit results

### `statamic.system.health-check`
Comprehensive system health analysis.

**Parameters:**
- `checks` (array, optional) - Specific checks to run
- `include_recommendations` (boolean, optional) - Include improvement recommendations

### `statamic.system.info`
Get comprehensive system and Statamic installation information.

**Parameters:**
- `include_config` (boolean, optional) - Include configuration details
- `include_environment` (boolean, optional) - Include environment information
- `include_cache` (boolean, optional) - Include cache configuration
- `include_collections` (boolean, optional) - Include content information
- `include_addons` (boolean, optional) - Include addon information

### `statamic.system.license-management`
Manage Statamic licenses and activation.

**Parameters:**
- `operation` (string, required) - Operation (check, refresh, deactivate)
- `license_key` (string, optional) - License key for activation

### `statamic.system.performance-monitor`
Monitor system performance and identify bottlenecks.

**Parameters:**
- `duration` (integer, optional) - Monitoring duration in minutes
- `include_queries` (boolean, optional) - Include database query analysis
- `include_cache` (boolean, optional) - Include cache performance

### `statamic.system.preferences-management`
Manage user and system preferences.

**Parameters:**
- `scope` (string, required) - Preference scope (user, system, site)
- `preferences` (object, optional) - Updated preferences
- `user` (string, optional) - User ID for user preferences

### `statamic.system.search-index-analyzer`
Analyze and optimize search indexes.

**Parameters:**
- `index` (string, optional) - Specific index to analyze
- `include_suggestions` (boolean, optional) - Include optimization suggestions
- `rebuild` (boolean, optional) - Rebuild index after analysis

### `statamic.system.sites`
System-level site management and configuration.

**Parameters:**
- `operation` (string, required) - Operation (list, analyze, optimize)
- `include_performance` (boolean, optional) - Include performance metrics

### `statamic.system.stache-management`
Advanced Stache (cache) management and optimization.

**Parameters:**
- `operation` (string, required) - Operation (clear, warm, analyze, optimize)
- `stores` (array, optional) - Specific stores to operate on
- `include_stats` (boolean, optional) - Include statistics

### `statamic.system.tools.discover`
Discover available system tools and utilities.

**Parameters:**
- `category` (string, optional) - Tool category filter
- `include_descriptions` (boolean, optional) - Include tool descriptions

### `statamic.system.tools.schema`
Get schema information for system tools and APIs.

**Parameters:**
- `tool` (string, optional) - Specific tool schema
- `format` (string, optional) - Schema format (json, yaml)

## Tags Tools

Template tag discovery and management.

### `statamic.tags.list`
List all available Statamic template tags.

**Parameters:**
- `include_parameters` (boolean, optional) - Include tag parameters
- `include_examples` (boolean, optional) - Include usage examples
- `filter` (string, optional) - Filter by tag name
- `category` (string, optional) - Filter by category

## Taxonomies Tools

Taxonomy structure and term management.

### `statamic.taxonomies.analyze`
Analyze taxonomy usage and relationships.

**Parameters:**
- `handle` (string, optional) - Specific taxonomy to analyze
- `include_relationships` (boolean, optional) - Include relationship analysis
- `include_usage_stats` (boolean, optional) - Include usage statistics

### `statamic.taxonomies.create`
Create a new taxonomy with configuration.

**Parameters:**
- `handle` (string, required) - Taxonomy handle
- `title` (string, required) - Taxonomy title
- `blueprint` (string, optional) - Default blueprint
- `sites` (array, optional) - Available sites
- `collections` (array, optional) - Associated collections

### `statamic.taxonomies.delete`
Delete a taxonomy and optionally its terms.

**Parameters:**
- `handle` (string, required) - Taxonomy handle
- `delete_terms` (boolean, optional) - Delete all terms (default: false)

### `statamic.taxonomies.get`
Retrieve detailed taxonomy configuration.

**Parameters:**
- `handle` (string, required) - Taxonomy handle
- `include_terms_count` (boolean, optional) - Include term count
- `include_collections` (boolean, optional) - Include associated collections

### `statamic.taxonomies.list`
List all taxonomies with configuration and metadata.

**Parameters:**
- `include_meta` (boolean, optional) - Include metadata and configuration
- `filter` (string, optional) - Filter results by name/handle
- `limit` (integer, optional) - Limit results
- `include_blueprint` (boolean, optional) - Include blueprint structure

### `statamic.taxonomies.terms`
Manage taxonomy terms and their relationships.

**Parameters:**
- `taxonomy` (string, required) - Taxonomy handle
- `operation` (string, required) - Operation (list, create, update, delete)
- `term_data` (object, optional) - Term data for create/update operations
- `term_id` (string, optional) - Term ID for specific operations

### `statamic.taxonomies.update`
Update taxonomy configuration and settings.

**Parameters:**
- `handle` (string, required) - Taxonomy handle
- `title` (string, optional) - Updated title
- `blueprint` (string, optional) - Default blueprint
- `sites` (array, optional) - Available sites
- `collections` (array, optional) - Associated collections

## Templates Tools

Template analysis and optimization.

### `statamic.templates.analyze-performance`
Analyze template performance and identify bottlenecks.

**Parameters:**
- `template_path` (string, optional) - Specific template to analyze
- `include_suggestions` (boolean, optional) - Include optimization suggestions
- `benchmark_runs` (integer, optional) - Number of benchmark runs

### `statamic.templates.detect-unused`
Detect unused templates and template variables.

**Parameters:**
- `template_type` (string, optional) - Template type (antlers, blade)
- `include_partials` (boolean, optional) - Include partial templates
- `scan_depth` (integer, optional) - Directory scan depth

### `statamic.templates.extract-variables`
Extract variables and dependencies from templates.

**Parameters:**
- `template_path` (string, required) - Template file path
- `include_dependencies` (boolean, optional) - Include template dependencies
- `analyze_relationships` (boolean, optional) - Analyze variable relationships

### `statamic.templates.suggest-optimizations`
Get optimization suggestions for templates.

**Parameters:**
- `template_path` (string, required) - Template file path
- `performance_focus` (boolean, optional) - Focus on performance optimizations
- `accessibility_focus` (boolean, optional) - Focus on accessibility improvements

## Terms Tools

Taxonomy term CRUD operations.

### `statamic.terms.create`
Create a new taxonomy term.

**Parameters:**
- `taxonomy` (string, required) - Taxonomy handle
- `data` (object, required) - Term data
- `slug` (string, optional) - Custom slug
- `site` (string, optional) - Site handle

### `statamic.terms.delete`
Delete a taxonomy term.

**Parameters:**
- `taxonomy` (string, required) - Taxonomy handle
- `id` (string, required) - Term ID
- `site` (string, optional) - Site handle

### `statamic.terms.get`
Retrieve specific taxonomy term.

**Parameters:**
- `taxonomy` (string, required) - Taxonomy handle
- `id` (string, required) - Term ID
- `site` (string, optional) - Site handle
- `include_relationships` (boolean, optional) - Include related entries

### `statamic.terms.list`
List taxonomy terms with filtering and pagination.

**Parameters:**
- `taxonomy` (string, optional) - Taxonomy handle (all if omitted)
- `site` (string, optional) - Site handle
- `limit` (integer, optional) - Limit results
- `offset` (integer, optional) - Pagination offset
- `sort` (string, optional) - Sort field

### `statamic.terms.update`
Update an existing taxonomy term.

**Parameters:**
- `taxonomy` (string, required) - Taxonomy handle
- `id` (string, required) - Term ID
- `data` (object, required) - Updated term data
- `site` (string, optional) - Site handle

## Users Tools

User management and administration.

### `statamic.users.activate`
Activate or deactivate user accounts.

**Parameters:**
- `id` (string, required) - User ID
- `active` (boolean, required) - Activation status

### `statamic.users.create`
Create a new user account.

**Parameters:**
- `email` (string, required) - User email
- `name` (string, optional) - User name
- `password` (string, optional) - User password
- `roles` (array, optional) - User roles
- `groups` (array, optional) - User groups
- `data` (object, optional) - Additional user data

### `statamic.users.delete`
Delete a user account.

**Parameters:**
- `id` (string, required) - User ID
- `transfer_content` (string, optional) - Transfer content to user ID

### `statamic.users.get`
Retrieve specific user details.

**Parameters:**
- `id` (string, required) - User ID
- `include_roles` (boolean, optional) - Include user roles
- `include_groups` (boolean, optional) - Include user groups

### `statamic.users.list`
List users with filtering and pagination.

**Parameters:**
- `role` (string, optional) - Filter by role
- `group` (string, optional) - Filter by group
- `active` (boolean, optional) - Filter by active status
- `limit` (integer, optional) - Limit results
- `search` (string, optional) - Search users

### `statamic.users.update`
Update user account and data.

**Parameters:**
- `id` (string, required) - User ID
- `email` (string, optional) - Updated email
- `name` (string, optional) - Updated name
- `roles` (array, optional) - Updated roles
- `groups` (array, optional) - Updated groups
- `data` (object, optional) - Updated user data

## Common Response Format

All tools return responses in this standardized format:

```json
{
  "success": true,
  "data": {
    "system_info": "Tool-specific response data"
  },
  "meta": {
    "statamic_version": "5.46.0",
    "laravel_version": "12.0",
    "timestamp": "2025-01-01T12:00:00Z",
    "tool": "statamic.system.info",
    "cache_status": "cleared"
  },
  "errors": [],
  "warnings": []
}
```

## Error Handling

Tools use standardized error codes:

- `INVALID_INPUT` - Invalid parameters or data
- `RESOURCE_NOT_FOUND` - Requested resource doesn't exist
- `PERMISSION_DENIED` - Insufficient permissions
- `VALIDATION_FAILED` - Data validation failed
- `OPERATION_FAILED` - Operation could not be completed
- `CACHE_ERROR` - Cache operation failed
- `FILE_NOT_FOUND` - File not found
- `PATH_TRAVERSAL` - Path traversal security violation

## Security Features

- **Path Validation**: All file operations validate against path traversal
- **Input Sanitization**: Automatic sanitization of sensitive data in logs
- **Access Control**: Permission-based access to operations
- **Rate Limiting**: Protection against abuse (configurable)
- **Audit Logging**: All operations are logged with correlation IDs

## Performance Features

- **Smart Caching**: Dependency-aware caching with automatic invalidation
- **Pagination**: All list operations support limit/offset
- **Response Limits**: Automatic truncation to prevent token overflow
- **Selective Loading**: Optional inclusion of expensive data
- **Background Processing**: Long operations can run in background

This comprehensive MCP server transforms Statamic into a fully API-accessible platform with enterprise-level management capabilities.