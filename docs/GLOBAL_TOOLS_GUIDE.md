# Global Tools Guide

This guide covers the comprehensive Global Sets and Values management tools that provide full CRUD capabilities while maintaining clear architectural separation.

## Architecture Overview

### Key Distinction: Sets vs Values

**Global Sets (Structure)** = Blueprint definitions, field schemas, multi-site configuration
**Global Values (Content)** = Actual data and content within those structures

This separation follows Statamic's core architecture and provides clean tool organization:
- `statamic.globals.sets.*` - Structure management
- `statamic.globals.values.*` - Content management

## Global Sets Tools (Structure Management)

### `statamic.globals.sets.list`
List all global sets with their structural configuration.

**Parameters:**
- `include_blueprint_info` (boolean, optional) - Include detailed blueprint information
- `include_localizations` (boolean, optional) - Include localization status across sites

**Response:**
```json
{
  "global_sets": [
    {
      "handle": "footer",
      "title": "Footer Settings",
      "sites": ["default", "dk"],
      "blueprint": {
        "handle": "footer",
        "title": "Footer Settings",
        "field_count": 5,
        "has_fields": true
      },
      "localizations": {
        "default": {"exists": true, "has_data": true},
        "dk": {"exists": true, "has_data": false}
      }
    }
  ],
  "count": 1,
  "meta": {
    "blueprint_info_included": true,
    "localizations_included": true
  }
}
```

### `statamic.globals.sets.get`
Get detailed information about a specific global set structure.

**Parameters:**
- `handle` (string, required) - Global set handle
- `include_blueprint_fields` (boolean, optional) - Include detailed field definitions
- `include_sample_data` (boolean, optional) - Generate sample data structure

**Response:**
```json
{
  "handle": "footer",
  "title": "Footer Settings",
  "sites": ["default", "dk"],
  "has_blueprint": true,
  "blueprint": {
    "handle": "footer",
    "title": "Footer Settings",
    "namespace": "globals",
    "fields": {
      "copyright": {
        "type": "text",
        "display": "Copyright Text",
        "instructions": "Copyright notice for footer",
        "required": true,
        "config": {...}
      },
      "social_links": {
        "type": "replicator",
        "display": "Social Links",
        "config": {...}
      }
    }
  },
  "sample_data_structure": {
    "copyright": "Sample text value",
    "social_links": [
      {"type": "social_link", "platform": "twitter", "url": "https://example.com"}
    ]
  },
  "localizations": {
    "default": {
      "exists": true,
      "has_data": true,
      "data_keys": ["copyright", "social_links"]
    }
  }
}
```

### `statamic.globals.sets.create`
Create a new global set with optional blueprint and initial values.

**Parameters:**
- `handle` (string, required) - Unique handle for the global set
- `title` (string, optional) - Display title (auto-generated if not provided)
- `sites` (array, optional) - Site handles (defaults to all sites)
- `blueprint` (string, optional) - Blueprint handle to use
- `initial_values` (object, optional) - Initial values for default site

**Example:**
```json
{
  "handle": "company_info",
  "title": "Company Information",
  "sites": ["default", "dk"],
  "blueprint": "company_info",
  "initial_values": {
    "company_name": "Acme Corp",
    "phone": "+45 12 34 56 78",
    "email": "info@acme.com"
  }
}
```

### `statamic.globals.sets.delete`
Delete a global set and all its values across all sites.

**Parameters:**
- `handle` (string, required) - Handle of global set to delete
- `confirm_deletion` (boolean, required) - Must be true to proceed
- `backup_values` (boolean, optional) - Create backup before deletion

**Safety Features:**
- Requires explicit confirmation
- Optional backup of all values across sites
- Returns detailed information about what was deleted

## Global Values Tools (Content Management)

### `statamic.globals.values.list`
List global values across all sets and sites with filtering options.

**Parameters:**
- `site` (string, optional) - Specific site handle (defaults to default site)
- `global_set` (string, optional) - Filter to specific global set
- `include_empty_values` (boolean, optional) - Include fields with empty values
- `limit` (integer, optional) - Limit number of sets to process

**Response:**
```json
{
  "global_values": {
    "footer": {
      "title": "Footer Settings",
      "handle": "footer",
      "site": "default",
      "values": {
        "copyright": "© 2025 Acme Corp",
        "social_links": [
          {"platform": "twitter", "url": "https://twitter.com/acme"}
        ]
      },
      "value_count": 2,
      "last_modified": "2025-01-15T10:30:00Z"
    }
  },
  "site": "default",
  "total_sets_with_values": 1,
  "meta": {
    "filtered_by_set": null,
    "empty_values_included": false,
    "limit_applied": null
  }
}
```

### `statamic.globals.values.get`
Get specific global values from a set with field filtering.

**Parameters:**
- `global_set` (string, required) - Global set handle
- `site` (string, optional) - Site handle (defaults to default site)
- `fields` (array, optional) - Specific field handles to retrieve
- `include_metadata` (boolean, optional) - Include metadata about the set

**Example - Get specific fields:**
```json
{
  "global_set": "footer",
  "site": "default",
  "fields": ["copyright", "social_links"]
}
```

### `statamic.globals.values.update`
Update global values with validation and change tracking.

**Parameters:**
- `global_set` (string, required) - Global set handle
- `values` (object, required) - Key-value pairs to update
- `site` (string, optional) - Site handle (defaults to default site)
- `merge_values` (boolean, optional) - Merge (true) or replace all (false)
- `validate_fields` (boolean, optional) - Validate against blueprint

**Response includes:**
- Previous and new values
- Detailed change tracking (added, modified, removed fields)
- Validation results
- Automatic cache clearing

**Example:**
```json
{
  "global_set": "footer",
  "values": {
    "copyright": "© 2025 Updated Corp",
    "new_field": "New content"
  },
  "site": "default",
  "merge_values": true,
  "validate_fields": true
}
```

**Change Tracking Response:**
```json
{
  "success": true,
  "changes": {
    "added": {
      "new_field": "New content"
    },
    "modified": {
      "copyright": {
        "old": "© 2025 Acme Corp",
        "new": "© 2025 Updated Corp"
      }
    },
    "removed": {}
  },
  "metadata": {
    "updated_at": "2025-01-15T10:35:00Z",
    "total_fields": 3,
    "fields_modified": 2,
    "validation_performed": true
  }
}
```

## Key Features

### Multi-Site Support
All tools fully support multi-site configurations:
- Site-specific content management
- Localization status tracking
- Cross-site value operations
- Site handle validation

### Blueprint Integration
- Automatic field validation against blueprints
- Field type-aware sample data generation
- Blueprint change detection
- Structured field definitions

### Performance & Safety
- Automatic cache clearing after changes
- Optional backup creation before destructive operations
- Detailed change tracking and audit logs
- Input validation and sanitization
- Error handling with helpful suggestions

### Developer Experience
- Clear separation between structure and content
- Consistent API patterns across all tools
- Comprehensive error messages with suggestions
- Metadata-rich responses for debugging
- Support for both flat-file and database storage

## Usage Examples

### Create a Contact Information Global Set
```json
{
  "tool": "statamic.globals.sets.create",
  "arguments": {
    "handle": "contact_info",
    "title": "Contact Information",
    "sites": ["default", "dk"],
    "initial_values": {
      "phone": "+45 12 34 56 78",
      "email": "contact@company.com",
      "address": "Copenhagen, Denmark"
    }
  }
}
```

### Update Multi-Site Content
```json
{
  "tool": "statamic.globals.values.update",
  "arguments": {
    "global_set": "contact_info",
    "site": "dk",
    "values": {
      "phone": "+45 87 65 43 21",
      "address": "København, Danmark"
    },
    "merge_values": true
  }
}
```

### Get All Global Content for a Site
```json
{
  "tool": "statamic.globals.values.list",
  "arguments": {
    "site": "default",
    "include_empty_values": false
  }
}
```

This architecture provides comprehensive global management while maintaining clear separation of concerns and following Statamic best practices.