# Statamic MCP Tools API Reference

This document provides a comprehensive reference for all MCP (Model Context Protocol) tools available in the Statamic MCP addon. Each tool follows the naming convention `statamic.{domain}.{action}` and is designed for single-purpose operations.

## Overview

The Statamic MCP server provides **138 tools** organized into **15 categories**, following strict separation of concerns between MCP tools (deterministic data access), LLM (reasoning), and user prompts (instructions).

### Tool Categories

- [Blueprints](#blueprints-tools) - Blueprint schema management
- [Collections](#collections-tools) - Collection configuration  
- [Entries](#entries-tools) - Entry content operations
- [Assets](#assets-tools) - Asset management
- [Taxonomies](#taxonomies-tools) - Taxonomy structure
- [Terms](#terms-tools) - Taxonomy term management
- [Globals](#globals-tools) - Global sets and values
- [Forms](#forms-tools) - Form configuration and submissions
- [Users](#users-tools) - User management
- [Roles](#roles-tools) - Role and permission management
- [Navigations](#navigations-tools) - Navigation management
- [Sites](#sites-tools) - Multi-site configuration
- [Development](#development-tools) - Developer tooling
- [System](#system-tools) - System management
- [Fieldsets](#fieldsets-tools) - Fieldset management

---

## Blueprints Tools

**Purpose**: Manage blueprint definitions, field structures, and schema validation.

### Core Operations

#### `statamic.blueprints.scan`
**Description**: Parse blueprints and fieldsets into normalized schema with performance optimization

**Parameters**:
- `paths` (optional) - Comma-separated list of paths to scan for blueprints
- `limit` (optional) - Limit number of blueprints returned (default: 50)
- `include_fields` (optional) - Include detailed field information (default: true)

#### `statamic.blueprints.list`
**Description**: List available blueprints with filtering options

**Parameters**:
- `namespace` (optional) - Filter by blueprint namespace
- `include_details` (optional) - Include detailed blueprint information

#### `statamic.blueprints.get`
**Description**: Get detailed information about a specific blueprint

**Parameters**:
- `handle` (required) - Blueprint handle/identifier
- `include_fields` (optional) - Include field definitions

#### `statamic.blueprints.create`
**Description**: Create a new blueprint with field definitions

**Parameters**:
- `handle` (required) - Blueprint handle
- `title` (optional) - Blueprint title
- `fields` (required) - Field definitions array
- `namespace` (optional) - Blueprint namespace

#### `statamic.blueprints.update`
**Description**: Update existing blueprint structure

**Parameters**:
- `handle` (required) - Blueprint handle
- `fields` (optional) - Updated field definitions
- `title` (optional) - Updated title

#### `statamic.blueprints.delete`
**Description**: Delete a blueprint with safety checks

**Parameters**:
- `handle` (required) - Blueprint handle
- `force` (optional) - Force deletion despite dependencies

### Advanced Operations

#### `statamic.blueprints.validate`
**Description**: Validate blueprint structure and field definitions

**Parameters**:
- `handle` (required) - Blueprint handle
- `check_dependencies` (optional) - Validate field dependencies

#### `statamic.blueprints.generate`
**Description**: Generate blueprint from content analysis

**Parameters**:
- `collection` (optional) - Source collection for analysis
- `sample_entries` (optional) - Number of entries to analyze

#### `statamic.blueprints.types`
**Description**: Generate TypeScript/PHP types from blueprint

**Parameters**:
- `handle` (required) - Blueprint handle
- `format` (optional) - Output format (typescript, php)

#### `statamic.blueprints.detect_field_conflicts`
**Description**: Detect field conflicts between blueprints

**Parameters**:
- `handles` (optional) - Specific blueprint handles to check

#### `statamic.blueprints.check_field_dependencies`
**Description**: Check field dependencies and relationships

**Parameters**:
- `handle` (required) - Blueprint handle

---

## Collections Tools

**Purpose**: Manage collection configuration, structure, and settings.

#### `statamic.collections.list`
**Description**: List all collections with metadata

**Parameters**:
- `include_meta` (optional) - Include metadata and configuration
- `limit` (optional) - Limit results

#### `statamic.collections.get`
**Description**: Get detailed information about a specific collection

**Parameters**:
- `handle` (required) - Collection handle/identifier

#### `statamic.collections.create`
**Description**: Create a new collection

**Parameters**:
- `handle` (required) - Collection handle
- `title` (required) - Collection title
- `blueprint` (optional) - Associated blueprint
- `route` (optional) - Collection route pattern

#### `statamic.collections.update`
**Description**: Update collection configuration

**Parameters**:
- `handle` (required) - Collection handle
- `title` (optional) - Updated title
- `route` (optional) - Updated route pattern

#### `statamic.collections.delete`
**Description**: Delete a collection with safety checks

**Parameters**:
- `handle` (required) - Collection handle
- `force` (optional) - Force deletion despite entries

#### `statamic.collections.reorder`
**Description**: Reorder collections

**Parameters**:
- `order` (required) - Array of collection handles in desired order

---

## Entries Tools

**Purpose**: Manage entry content within collections with CRUD operations.

#### `statamic.entries.list`
**Description**: List entries from a specific collection with filtering and pagination

**Parameters**:
- `collection` (required) - Collection handle
- `filter` (optional) - Filter entries by title or slug
- `limit` (optional) - Limit number of results (default: 50)
- `offset` (optional) - Offset for pagination
- `include_data` (optional) - Include entry data
- `status` (optional) - Filter by status (published, draft)

#### `statamic.entries.get`
**Description**: Get detailed information about a specific entry

**Parameters**:
- `id` (required) - Entry ID
- `include_data` (optional) - Include entry data fields

#### `statamic.entries.create`
**Description**: Create a new entry

**Parameters**:
- `collection` (required) - Collection handle
- `data` (required) - Entry data fields
- `slug` (optional) - Entry slug
- `published` (optional) - Publication status

#### `statamic.entries.update`
**Description**: Update an existing entry

**Parameters**:
- `id` (required) - Entry ID
- `data` (optional) - Updated data fields
- `published` (optional) - Publication status

#### `statamic.entries.delete`
**Description**: Delete an entry

**Parameters**:
- `id` (required) - Entry ID
- `force` (optional) - Force deletion

#### `statamic.entries.search`
**Description**: Search entries across collections

**Parameters**:
- `query` (required) - Search query
- `collections` (optional) - Collections to search
- `limit` (optional) - Results limit

#### `statamic.entries.duplicate`
**Description**: Duplicate an entry

**Parameters**:
- `id` (required) - Source entry ID
- `title` (optional) - New entry title

#### `statamic.entries.publish`
**Description**: Publish an entry

**Parameters**:
- `id` (required) - Entry ID

#### `statamic.entries.unpublish`
**Description**: Unpublish an entry

**Parameters**:
- `id` (required) - Entry ID

### Advanced Entry Operations

#### `statamic.entries.batch_operation`
**Description**: Perform batch operations on multiple entries

**Parameters**:
- `operation` (required) - Operation type (publish, unpublish, delete)
- `entries` (required) - Array of entry IDs

#### `statamic.entries.create_or_update`
**Description**: Create or update entry based on existence

**Parameters**:
- `collection` (required) - Collection handle
- `data` (required) - Entry data
- `identifier` (optional) - Unique identifier for matching

#### `statamic.entries.versioning`
**Description**: Manage entry versions and revisions

**Parameters**:
- `id` (required) - Entry ID
- `action` (required) - Version action (list, restore, compare)

#### `statamic.entries.scheduling_workflow`
**Description**: Manage entry scheduling and workflow

**Parameters**:
- `id` (required) - Entry ID
- `schedule_date` (optional) - Scheduled publication date

#### `statamic.entries.manage_relationships`
**Description**: Manage entry relationships and references

**Parameters**:
- `id` (required) - Entry ID
- `action` (required) - Relationship action

#### `statamic.entries.import_export`
**Description**: Import/export entries in various formats

**Parameters**:
- `action` (required) - import or export
- `collection` (required) - Collection handle
- `format` (optional) - Data format (csv, json, yaml)

---

## Assets Tools

**Purpose**: Manage digital assets, containers, and file operations.

#### `statamic.assets.list`
**Description**: List assets with filtering options

**Parameters**:
- `container` (optional) - Asset container handle
- `folder` (optional) - Folder path
- `filter` (optional) - Filter by filename or type

#### `statamic.assets.get`
**Description**: Get detailed information about a specific asset

**Parameters**:
- `id` (required) - Asset ID or path
- `include_meta` (optional) - Include asset metadata

#### `statamic.assets.create`
**Description**: Create/upload a new asset

**Parameters**:
- `container` (required) - Asset container handle
- `path` (required) - Asset path
- `data` (optional) - Asset metadata

#### `statamic.assets.update`
**Description**: Update asset metadata

**Parameters**:
- `id` (required) - Asset ID
- `data` (required) - Updated metadata

#### `statamic.assets.delete`
**Description**: Delete an asset

**Parameters**:
- `id` (required) - Asset ID
- `force` (optional) - Force deletion

#### `statamic.assets.copy`
**Description**: Copy an asset to another location

**Parameters**:
- `source` (required) - Source asset ID
- `destination` (required) - Destination path

#### `statamic.assets.move`
**Description**: Move an asset to another location

**Parameters**:
- `id` (required) - Asset ID
- `destination` (required) - Destination path

#### `statamic.assets.rename`
**Description**: Rename an asset

**Parameters**:
- `id` (required) - Asset ID
- `new_name` (required) - New filename

---

## Taxonomies Tools

**Purpose**: Manage taxonomy structures and configuration.

#### `statamic.taxonomies.list`
**Description**: List all taxonomies with optional filtering and metadata

**Parameters**:
- `include_meta` (optional) - Include metadata and configuration
- `filter` (optional) - Filter results by name/handle
- `include_blueprint` (optional) - Include blueprint structure
- `limit` (optional) - Limit results

#### `statamic.taxonomies.get`
**Description**: Get detailed information about a specific taxonomy

**Parameters**:
- `handle` (required) - Taxonomy handle

#### `statamic.taxonomies.create`
**Description**: Create a new taxonomy

**Parameters**:
- `handle` (required) - Taxonomy handle
- `title` (required) - Taxonomy title
- `blueprint` (optional) - Associated blueprint

#### `statamic.taxonomies.update`
**Description**: Update taxonomy configuration

**Parameters**:
- `handle` (required) - Taxonomy handle
- `title` (optional) - Updated title
- `sites` (optional) - Site configuration

#### `statamic.taxonomies.delete`
**Description**: Delete a taxonomy

**Parameters**:
- `handle` (required) - Taxonomy handle
- `force` (optional) - Force deletion despite terms

#### `statamic.taxonomies.analyze`
**Description**: Analyze taxonomy usage and relationships

**Parameters**:
- `handle` (required) - Taxonomy handle

#### `statamic.taxonomies.list_terms`
**Description**: List terms within a taxonomy

**Parameters**:
- `taxonomy` (required) - Taxonomy handle
- `limit` (optional) - Limit results
- `include_data` (optional) - Include term data

---

## Terms Tools

**Purpose**: Manage individual taxonomy terms.

#### `statamic.terms.list`
**Description**: List terms across taxonomies

**Parameters**:
- `taxonomy` (optional) - Filter by taxonomy
- `limit` (optional) - Limit results
- `include_data` (optional) - Include term data

#### `statamic.terms.get`
**Description**: Get detailed information about a specific term

**Parameters**:
- `id` (required) - Term ID
- `include_usage` (optional) - Include usage statistics

#### `statamic.terms.create`
**Description**: Create a new term

**Parameters**:
- `taxonomy` (required) - Taxonomy handle
- `slug` (required) - Term slug
- `data` (required) - Term data

#### `statamic.terms.update`
**Description**: Update an existing term

**Parameters**:
- `id` (required) - Term ID
- `data` (required) - Updated data

#### `statamic.terms.delete`
**Description**: Delete a term

**Parameters**:
- `id` (required) - Term ID
- `force` (optional) - Force deletion despite usage

---

## Globals Tools

**Purpose**: Manage global sets and their values across sites.

#### `statamic.globals.list_sets`
**Description**: List all global sets

**Parameters**:
- `include_meta` (optional) - Include metadata

#### `statamic.globals.get_set`
**Description**: Get global set configuration

**Parameters**:
- `handle` (required) - Global set handle

#### `statamic.globals.create_set`
**Description**: Create a new global set

**Parameters**:
- `handle` (required) - Global set handle
- `title` (required) - Global set title

#### `statamic.globals.update_set`
**Description**: Update global set configuration

**Parameters**:
- `handle` (required) - Global set handle
- `title` (optional) - Updated title

#### `statamic.globals.delete_set`
**Description**: Delete a global set

**Parameters**:
- `handle` (required) - Global set handle
- `force` (optional) - Force deletion

#### `statamic.globals.list_values`
**Description**: List global values across sets

**Parameters**:
- `set` (optional) - Filter by global set
- `site` (optional) - Filter by site

#### `statamic.globals.get_values`
**Description**: Get values for a specific global set

**Parameters**:
- `set` (required) - Global set handle
- `site` (optional) - Site handle

#### `statamic.globals.update_values`
**Description**: Update global values

**Parameters**:
- `set` (required) - Global set handle
- `values` (required) - Updated values
- `site` (optional) - Site handle

#### `statamic.globals.get`
**Description**: Get specific global value

**Parameters**:
- `set` (required) - Global set handle
- `key` (required) - Value key
- `site` (optional) - Site handle

#### `statamic.globals.update`
**Description**: Update specific global value

**Parameters**:
- `set` (required) - Global set handle
- `key` (required) - Value key
- `value` (required) - New value
- `site` (optional) - Site handle

---

## Forms Tools

**Purpose**: Manage forms, configuration, and form submissions.

#### `statamic.forms.list`
**Description**: List all forms

**Parameters**:
- `include_meta` (optional) - Include form metadata

#### `statamic.forms.get`
**Description**: Get detailed form information

**Parameters**:
- `handle` (required) - Form handle

#### `statamic.forms.create`
**Description**: Create a new form

**Parameters**:
- `handle` (required) - Form handle
- `title` (required) - Form title
- `fields` (required) - Form fields configuration

#### `statamic.forms.update`
**Description**: Update form configuration

**Parameters**:
- `handle` (required) - Form handle
- `title` (optional) - Updated title
- `fields` (optional) - Updated fields

#### `statamic.forms.delete`
**Description**: Delete a form

**Parameters**:
- `handle` (required) - Form handle
- `force` (optional) - Force deletion despite submissions

#### `statamic.forms.list_submissions`
**Description**: List form submissions

**Parameters**:
- `form` (required) - Form handle
- `limit` (optional) - Limit results
- `filter` (optional) - Filter submissions

#### `statamic.forms.get_submission`
**Description**: Get specific submission details

**Parameters**:
- `id` (required) - Submission ID

#### `statamic.forms.delete_submission`
**Description**: Delete a form submission

**Parameters**:
- `id` (required) - Submission ID

#### `statamic.forms.export_submissions`
**Description**: Export form submissions

**Parameters**:
- `form` (required) - Form handle
- `format` (optional) - Export format (csv, json)

#### `statamic.forms.submissions_stats`
**Description**: Get submission statistics

**Parameters**:
- `form` (required) - Form handle
- `period` (optional) - Time period for stats

---

## Users Tools

**Purpose**: User account management and authentication.

#### `statamic.users.list`
**Description**: List users with filtering and pagination

**Parameters**:
- `limit` (optional) - Maximum number of users (default: 50)
- `offset` (optional) - Number of users to skip (default: 0)
- `role` (optional) - Filter by user role
- `group` (optional) - Filter by user group
- `super` (optional) - Filter by super admin status
- `include_data` (optional) - Include user data fields (default: false)

#### `statamic.users.get`
**Description**: Get detailed user information

**Parameters**:
- `id` (required) - User ID
- `include_data` (optional) - Include user data fields

#### `statamic.users.create`
**Description**: Create a new user

**Parameters**:
- `email` (required) - User email
- `name` (optional) - User name
- `password` (optional) - User password
- `roles` (optional) - Array of role handles

#### `statamic.users.update`
**Description**: Update user information

**Parameters**:
- `id` (required) - User ID
- `name` (optional) - Updated name
- `email` (optional) - Updated email
- `roles` (optional) - Updated roles

#### `statamic.users.delete`
**Description**: Delete a user

**Parameters**:
- `id` (required) - User ID
- `force` (optional) - Force deletion

#### `statamic.users.activate`
**Description**: Activate a user account

**Parameters**:
- `id` (required) - User ID

---

## Roles Tools

**Purpose**: Role and permission management.

#### `statamic.roles.list`
**Description**: List all roles

**Parameters**:
- `include_permissions` (optional) - Include role permissions

#### `statamic.roles.get`
**Description**: Get detailed role information

**Parameters**:
- `handle` (required) - Role handle

#### `statamic.roles.create`
**Description**: Create a new role

**Parameters**:
- `handle` (required) - Role handle
- `title` (required) - Role title
- `permissions` (optional) - Array of permissions

#### `statamic.roles.update`
**Description**: Update role configuration

**Parameters**:
- `handle` (required) - Role handle
- `title` (optional) - Updated title
- `permissions` (optional) - Updated permissions

#### `statamic.roles.delete`
**Description**: Delete a role

**Parameters**:
- `handle` (required) - Role handle
- `force` (optional) - Force deletion despite users

---

## Navigations Tools

**Purpose**: Navigation structure and menu management.

#### `statamic.navigations.list`
**Description**: List all navigations

**Parameters**:
- `include_structure` (optional) - Include navigation structure

#### `statamic.navigations.get`
**Description**: Get detailed navigation information

**Parameters**:
- `handle` (required) - Navigation handle

#### `statamic.navigations.create`
**Description**: Create a new navigation

**Parameters**:
- `handle` (required) - Navigation handle
- `title` (required) - Navigation title

#### `statamic.navigations.update`
**Description**: Update navigation configuration

**Parameters**:
- `handle` (required) - Navigation handle
- `title` (optional) - Updated title
- `structure` (optional) - Updated structure

#### `statamic.navigations.delete`
**Description**: Delete a navigation

**Parameters**:
- `handle` (required) - Navigation handle

#### `statamic.navigations.list_content`
**Description**: List navigation content/items

**Parameters**:
- `navigation` (required) - Navigation handle
- `site` (optional) - Site handle

---

## Sites Tools

**Purpose**: Multi-site configuration and management.

#### `statamic.sites.list`
**Description**: List all sites

**Parameters**:
- `include_config` (optional) - Include site configuration

#### `statamic.sites.get`
**Description**: Get detailed site information

**Parameters**:
- `handle` (required) - Site handle

#### `statamic.sites.create`
**Description**: Create a new site

**Parameters**:
- `handle` (required) - Site handle
- `name` (required) - Site name
- `url` (required) - Site URL

#### `statamic.sites.update`
**Description**: Update site configuration

**Parameters**:
- `handle` (required) - Site handle
- `name` (optional) - Updated name
- `url` (optional) - Updated URL

#### `statamic.sites.delete`
**Description**: Delete a site

**Parameters**:
- `handle` (required) - Site handle

#### `statamic.sites.switch`
**Description**: Switch active site context

**Parameters**:
- `site` (required) - Site handle to switch to

---

## Development Tools

**Purpose**: Developer experience, template analysis, and code generation.

#### `statamic.development.templates`
**Description**: Template hints, validation, and optimization for Antlers/Blade

**Parameters**:
- `template_path` (optional) - Specific template to analyze
- `type` (optional) - Template type (antlers, blade)
- `check_performance` (optional) - Include performance analysis

#### `statamic.development.blade_hints`
**Description**: Analyze Blade templates for Statamic best practices

**Parameters**:
- `path` (optional) - Template path to analyze
- `check_statamic_usage` (optional) - Check Statamic-specific patterns

#### `statamic.development.blade_lint`
**Description**: Lint Blade templates for issues

**Parameters**:
- `path` (required) - Template path
- `rules` (optional) - Specific linting rules

#### `statamic.development.antlers_validate`
**Description**: Validate Antlers template syntax

**Parameters**:
- `template` (required) - Template content or path
- `strict` (optional) - Use strict validation

#### `statamic.development.generate_types`
**Description**: Generate TypeScript/PHP types from blueprints

**Parameters**:
- `blueprints` (optional) - Specific blueprints to process
- `format` (required) - Output format (typescript, php)
- `output_path` (optional) - Where to save generated types

#### `statamic.development.list_type_definitions`
**Description**: List available type definitions

**Parameters**:
- `format` (optional) - Filter by format

#### `statamic.development.addons`
**Description**: Addon development, analysis, and scaffolding

**Parameters**:
- `action` (required) - Action (list, analyze, scaffold)
- `addon` (optional) - Specific addon handle

#### `statamic.development.addon_discovery`
**Description**: Discover and analyze installed addons

**Parameters**:
- `include_inactive` (optional) - Include inactive addons

#### `statamic.development.console`
**Description**: Artisan command execution and management

**Parameters**:
- `command` (optional) - Artisan command to execute
- `arguments` (optional) - Command arguments

#### `statamic.development.extract_template_variables`
**Description**: Extract variables used in templates

**Parameters**:
- `template_path` (required) - Template to analyze
- `include_partials` (optional) - Include partial templates

#### `statamic.development.detect_unused_templates`
**Description**: Detect unused templates in the project

**Parameters**:
- `path` (optional) - Template directory to scan

#### `statamic.development.suggest_template_optimizations`
**Description**: Suggest template performance optimizations

**Parameters**:
- `template_path` (required) - Template to analyze

#### `statamic.development.analyze_template_performance`
**Description**: Analyze template performance characteristics

**Parameters**:
- `template_path` (required) - Template to analyze
- `include_suggestions` (optional) - Include optimization suggestions

#### `statamic.development.widgets`
**Description**: Widget development and management

**Parameters**:
- `action` (required) - Action (list, create, analyze)

---

## System Tools

**Purpose**: System information, cache management, and health monitoring.

#### `statamic.system.info`
**Description**: Get comprehensive system information

**Parameters**:
- `include_config` (optional) - Include configuration details
- `include_environment` (optional) - Include environment info
- `include_cache` (optional) - Include cache configuration
- `include_collections` (optional) - Include collections info

#### `statamic.system.cache_status`
**Description**: Get cache status and configuration

**Parameters**:
- `detailed` (optional) - Include detailed cache information

#### `statamic.system.clear_cache`
**Description**: Clear various caches

**Parameters**:
- `type` (optional) - Cache type to clear (stache, static, views, all)
- `force` (optional) - Force cache clearing

#### `statamic.system.health_check`
**Description**: Perform comprehensive system health check

**Parameters**:
- `include_performance` (optional) - Include performance metrics
- `check_permissions` (optional) - Check file permissions

#### `statamic.system.discover_tools`
**Description**: Discover available MCP tools

**Parameters**:
- `category` (optional) - Filter by tool category
- `include_schemas` (optional) - Include tool schemas

#### `statamic.system.get_tool_schema`
**Description**: Get schema for a specific tool

**Parameters**:
- `tool_name` (required) - Tool name to get schema for

#### `statamic.system.stache_management`
**Description**: Manage Statamic's stache (content cache)

**Parameters**:
- `action` (required) - Action (warm, clear, status)

#### `statamic.system.performance_monitor`
**Description**: Monitor system performance metrics

**Parameters**:
- `duration` (optional) - Monitoring duration

#### `statamic.system.search_index_analyzer`
**Description**: Analyze search index status and performance

**Parameters**:
- `rebuild` (optional) - Rebuild search index

#### `statamic.system.license_management`
**Description**: Manage Statamic license information

**Parameters**:
- `action` (optional) - Action (status, validate)

#### `statamic.system.preferences_management`
**Description**: Manage system preferences

**Parameters**:
- `action` (required) - Action (get, set)
- `key` (optional) - Preference key
- `value` (optional) - Preference value

#### `statamic.system.docs`
**Description**: Documentation search and discovery

**Parameters**:
- `query` (optional) - Search query
- `section` (optional) - Documentation section

---

## Fieldsets Tools

**Purpose**: Manage reusable fieldset definitions.

#### `statamic.fieldsets.list`
**Description**: List available fieldsets

**Parameters**:
- `include_fields` (optional) - Include field definitions

#### `statamic.fieldsets.get`
**Description**: Get specific fieldset details

**Parameters**:
- `handle` (required) - Fieldset handle

#### `statamic.fieldsets.scan`
**Description**: Scan and parse fieldsets with optimization

**Parameters**:
- `paths` (optional) - Paths to scan
- `include_fields` (optional) - Include field details

#### `statamic.fieldsets.create`
**Description**: Create a new fieldset

**Parameters**:
- `handle` (required) - Fieldset handle
- `title` (required) - Fieldset title
- `fields` (required) - Field definitions

#### `statamic.fieldsets.update`
**Description**: Update fieldset configuration

**Parameters**:
- `handle` (required) - Fieldset handle
- `fields` (optional) - Updated fields

#### `statamic.fieldsets.delete`
**Description**: Delete a fieldset

**Parameters**:
- `handle` (required) - Fieldset handle

---

## Additional Tools

### Field Types
- `statamic.field_types.list` - List available field types with configuration options

### Tags
- `statamic.tags.list` - List available Antlers tags with usage examples

### Modifiers
- `statamic.modifiers.list` - List available Antlers modifiers

### Filters
- `statamic.filters.list` - List available query filters and scopes

### Scopes
- `statamic.scopes.list` - List available query scopes

### Permissions
- `statamic.permissions.list` - List available permissions

### Groups (User Groups)
- `statamic.groups.list` - List user groups
- `statamic.groups.get` - Get specific group details

---

## Response Format

All tools return responses in a consistent format with metadata:

```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "statamic_version": "v5.46",
    "laravel_version": "12.0",
    "timestamp": "2025-01-01T12:00:00Z",
    "tool": "tool_name"
  },
  "errors": [],
  "warnings": []
}
```

## Common Parameters

Many tools share common parameters:
- `limit` - Limit number of results (usually default: 50)
- `offset` - Offset for pagination
- `include_meta` - Include additional metadata
- `include_data` - Include full data fields
- `filter` - Filter results by text search

## Error Handling

Tools provide structured error responses with:
- Clear error messages
- Context about what failed
- Suggestions for resolution where applicable
- Validation errors for invalid parameters

## Cache Management

All structural and content changes automatically clear relevant caches:
- **Blueprint changes**: Clears stache, static, views
- **Content changes**: Clears stache, static  
- **Structure changes**: Clears stache, static, views

## Performance Considerations

Tools are optimized for performance with:
- Pagination support to prevent token overflow
- Field filtering options (`include_fields: false`)
- Response size limits (< 25,000 tokens)
- Intelligent defaults for large datasets

---

*This API reference covers 138 tools across 15 categories. Each tool is designed for single-purpose operations following MCP best practices for deterministic data access and manipulation.*