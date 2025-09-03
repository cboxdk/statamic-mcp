# MCP Tools Reference

Complete reference for all 11 MCP tools provided by the Statamic MCP Server.

## System & Installation Tools

### `statamic.system.info`
Get comprehensive information about your Statamic installation.

**Parameters:**
- `include_config` (boolean, optional) - Include configuration details
- `include_environment` (boolean, optional) - Include environment information  
- `include_cache` (boolean, optional) - Include cache configuration
- `include_collections` (boolean, optional) - Include content information

**Example Usage:**
```json
{
  "include_config": true,
  "include_environment": true,
  "include_cache": true,
  "include_collections": true
}
```

**Returns:**
- Statamic version, edition (Pro/Solo), licensing
- Storage type: file-based, database (Runway), mixed
- Cache configuration and status
- Multi-site setup and configuration
- Collections, taxonomies, and asset containers
- Environment and server information

### `statamic.addons.scan`
Scan and analyze installed Statamic addons.

**Parameters:**
- `include_tags` (boolean, optional) - Include addon tags
- `include_modifiers` (boolean, optional) - Include addon modifiers
- `include_fieldtypes` (boolean, optional) - Include addon field types
- `include_documentation` (boolean, optional) - Include documentation links
- `addon_filter` (string, optional) - Filter by addon name

**Example Usage:**
```json
{
  "include_tags": true,
  "include_modifiers": true,
  "addon_filter": "seo-pro"
}
```

**Returns:**
- Installed addons from composer.json
- Addon-specific tags, modifiers, and field types
- Community resources and marketplace information
- Documentation links for each addon

## Blueprint & Field Tools

### `statamic.blueprints.scan`
Scan and analyze Statamic blueprints and fieldsets.

**Parameters:**
- `include_relationships` (boolean, optional) - Include relationship mapping
- `include_validation` (boolean, optional) - Include validation rules
- `include_field_details` (boolean, optional) - Include detailed field information
- `blueprint_filter` (string, optional) - Filter by blueprint name

**Returns:**
- All blueprints with field structures
- Field type categorization and validation
- Relationship mapping between collections
- Set configurations for Bard and Replicator

### `statamic.fieldtypes.list`
List all available field types with configuration examples.

**Parameters:**
- `category` (string, optional) - Filter by category (text, media, relationships, etc.)
- `type` (string, optional) - Get specific field type details
- `include_examples` (boolean, optional) - Include configuration examples

**Categories:**
- `text` - Text, textarea, markdown, code
- `media` - Assets, video  
- `relationships` - Entries, taxonomy, users, collections
- `structured` - Replicator, grid, group, yaml, array
- `special` - Date, color, toggle, select, range
- `hidden` - Hidden, slug, template

### `statamic.blueprints.types`
Generate types from blueprints (TypeScript, PHP, JSON Schema).

**Parameters:**
- `blueprints` (string, optional) - Specific blueprint name
- `format` (string, required) - Output format: "ts", "php", "json"
- `include_validation` (boolean, optional) - Include validation rules
- `include_relationships` (boolean, optional) - Include relationship types

**Formats:**
- `ts` - TypeScript interfaces
- `php` - PHP classes with type hints
- `json` - JSON Schema for validation

## Documentation Tools

### `statamic.docs.search`
Search and retrieve Statamic documentation.

**Parameters:**
- `query` (string, required) - Search query
- `section` (string, optional) - Filter by section (core, fieldtypes, tags, templating)
- `include_content` (boolean, optional) - Include full content
- `limit` (integer, optional) - Limit results (default: 10)

**Sections:**
- `core` - Core Statamic concepts
- `fieldtypes` - Field type documentation
- `tags` - Template tags and parameters
- `templating` - Antlers and Blade guides
- `api` - REST API and GraphQL
- `addons` - Addon development

**Features:**
- Real-time content fetching from statamic.dev
- Intelligent relevance scoring
- Sitemap parsing for comprehensive coverage
- Addon documentation discovery

### `statamic.tags.scan`
Scan and discover Statamic Blade tags.

**Parameters:**
- `include_parameters` (boolean, optional) - Include tag parameters
- `include_examples` (boolean, optional) - Include usage examples
- `filter` (string, optional) - Filter by tag name
- `category` (string, optional) - Filter by category

**Returns:**
- All available Statamic Blade tags
- Tag parameters and usage patterns
- Examples and documentation links
- Category organization

## Template Development Tools

### `statamic.antlers.hints`
Get context-aware hints for Antlers templates.

**Parameters:**
- `blueprint` (string, optional) - Blueprint context
- `template_content` (string, optional) - Current template content
- `include_examples` (boolean, optional) - Include usage examples
- `field_context` (string, optional) - Specific field context

**Returns:**
- Available variables for current context
- Field suggestions based on blueprint
- Template patterns and best practices
- Relationship data availability

### `statamic.antlers.validate`
Validate Antlers template syntax against blueprints.

**Parameters:**
- `template` (string, required) - Template content to validate
- `blueprint` (string, optional) - Blueprint to validate against
- `strict_mode` (boolean, optional) - Enable strict validation

**Returns:**
- Syntax validation results
- Field availability checking
- Error messages with line numbers
- Suggestions for corrections

### `statamic.blade.hints`
Get Statamic-specific Blade component suggestions.

**Parameters:**
- `blueprint` (string, optional) - Blueprint context
- `include_components` (boolean, optional) - Include component suggestions
- `include_best_practices` (boolean, optional) - Include best practices

**Returns:**
- Recommended Blade components
- Statamic-specific patterns
- Best practice suggestions
- Anti-pattern warnings

### `statamic.blade.lint`
Comprehensive Blade linting with Statamic policies.

**Parameters:**
- `template` (string, required) - Blade template content
- `file_path` (string, optional) - File path for context
- `auto_fix` (boolean, optional) - Provide auto-fix suggestions
- `policy` (string, optional) - Linting policy level

**Linting Rules:**
- No inline PHP in templates
- No direct facade calls (Statamic, DB, Http, Cache)
- No database queries in views
- Required alt text for images
- Descriptive link text for accessibility
- Proper component usage patterns

**Policy Levels:**
- `strict` - All rules enforced
- `recommended` - Best practices only
- `basic` - Security and accessibility only

## Usage Examples

### System Analysis
```bash
# Get complete system information  
curl -X POST http://localhost/mcp/statamic.system.info \
  -d '{"include_config": true, "include_environment": true}'

# Check installed addons
curl -X POST http://localhost/mcp/statamic.addons.scan \
  -d '{"include_tags": true, "include_documentation": true}'
```

### Blueprint Analysis
```bash
# Scan all blueprints
curl -X POST http://localhost/mcp/statamic.blueprints.scan \
  -d '{"include_relationships": true}'

# Generate TypeScript types
curl -X POST http://localhost/mcp/statamic.blueprints.types \
  -d '{"format": "ts", "blueprints": "article"}'
```

### Documentation Search
```bash  
# Search for collection documentation
curl -X POST http://localhost/mcp/statamic.docs.search \
  -d '{"query": "collections", "section": "core", "include_content": true}'

# Find field type information
curl -X POST http://localhost/mcp/statamic.fieldtypes.list \
  -d '{"type": "bard", "include_examples": true}'
```

### Template Development
```bash
# Get Antlers hints for blueprint
curl -X POST http://localhost/mcp/statamic.antlers.hints \
  -d '{"blueprint": "article", "include_examples": true}'

# Validate Antlers template
curl -X POST http://localhost/mcp/statamic.antlers.validate \
  -d '{"template": "{{ title }}{{ content }}", "blueprint": "article"}'

# Lint Blade template  
curl -X POST http://localhost/mcp/statamic.blade.lint \
  -d '{"template": "@php $entries = Entry::all(); @endphp", "auto_fix": true}'
```

## Error Handling

All tools return consistent error responses:

```json
{
  "error": true,
  "message": "Error description",
  "code": "ERROR_CODE",
  "details": {
    "additional": "context"
  }
}
```

Common error codes:
- `BLUEPRINT_NOT_FOUND` - Blueprint doesn't exist
- `TEMPLATE_SYNTAX_ERROR` - Invalid template syntax  
- `FIELD_NOT_FOUND` - Field not in blueprint
- `VALIDATION_FAILED` - Validation rules failed
- `DOCUMENTATION_UNAVAILABLE` - Documentation not accessible