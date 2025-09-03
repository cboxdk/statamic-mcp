# MCP Tools Usage Examples

Real-world examples demonstrating the 135 MCP tools provided by the Statamic MCP Server.

## Table of Contents

- [System Analysis](#system-analysis)
- [Blueprint Management](#blueprint-management) 
- [Content Operations](#content-operations)
- [Template Development](#template-development)
- [E-commerce Setup](#e-commerce-setup)
- [Multi-site Management](#multi-site-management)
- [Form Processing](#form-processing)
- [Asset Management](#asset-management)
- [User Management](#user-management)
- [Performance Optimization](#performance-optimization)

## System Analysis

### Comprehensive System Health Check

**Scenario**: Analyzing a complete Statamic installation to understand its current state.

```bash
# Get system overview
curl -X POST http://localhost/mcp/statamic.system.info \
  -d '{"include_config": true, "include_environment": true, "include_collections": true}'

# Check system health
curl -X POST http://localhost/mcp/statamic.system.health-check \
  -d '{"include_recommendations": true}'

# Analyze cache performance  
curl -X POST http://localhost/mcp/statamic.system.cache.status \
  -d '{"include_sizes": true, "include_stats": true}'
```

**AI Assistant Response:**
> Your Statamic installation:
> - **Version**: 5.46.0 Pro (licensed)
> - **Storage**: File-based with Redis cache
> - **Collections**: 4 collections, 247 entries
> - **Multi-site**: 3 sites (EN, DA, DE)
> - **Health**: ✅ All systems operational
> - **Cache**: 89% hit rate, 234MB total size
> - **Recommendation**: Consider enabling static caching for better performance

### Addon Discovery and Analysis

**Scenario**: Understanding installed addons and their capabilities.

```bash
# Discover all addons with full details
curl -X POST http://localhost/mcp/statamic.development.addons \
  -d '{"include_tags": true, "include_modifiers": true, "include_fieldtypes": true, "include_documentation": true}'

# Analyze specific addon capabilities
curl -X POST http://localhost/mcp/statamic.development.addon-discovery \
  -d '{"addon_name": "seo-pro", "include_tags": true}'
```

**Response Example:**
```json
{
  "success": true,
  "data": {
    "addons": [
      {
        "name": "SEO Pro",
        "package": "statamic/seo-pro",
        "version": "6.2.1",
        "tags": ["seo", "seo:title", "seo:meta"],
        "modifiers": ["seo_title", "meta_description"],
        "fieldtypes": [],
        "documentation": "https://statamic.dev/seo-pro"
      }
    ]
  }
}
```

## Blueprint Management

### Creating a Complete Content Structure

**Scenario**: Setting up a blog with articles, authors, and categories.

```bash
# 1. Create article blueprint
curl -X POST http://localhost/mcp/statamic.blueprints.create \
  -d '{
    "handle": "article",
    "title": "Article",
    "namespace": "collections",
    "fields": [
      {"handle": "title", "field": {"type": "text", "required": true}},
      {"handle": "slug", "field": {"type": "slug", "from": "title"}},
      {"handle": "content", "field": {"type": "bard", "toolbar_buttons": ["h2","h3","bold","italic","link"]}},
      {"handle": "author", "field": {"type": "users", "max_items": 1}},
      {"handle": "categories", "field": {"type": "taxonomy", "taxonomies": ["categories"]}},
      {"handle": "featured_image", "field": {"type": "assets", "container": "main", "max_files": 1}},
      {"handle": "published_at", "field": {"type": "date"}}
    ]
  }'

# 2. Create categories taxonomy
curl -X POST http://localhost/mcp/statamic.taxonomies.create \
  -d '{
    "handle": "categories",
    "title": "Categories",
    "blueprint": "category"
  }'

# 3. Generate TypeScript types
curl -X POST http://localhost/mcp/statamic.blueprints.types \
  -d '{
    "blueprints": "article",
    "namespace": "collections",
    "format": "ts",
    "include_relationships": true
  }'
```

### Blueprint Analysis and Validation

**Scenario**: Analyzing existing blueprints for conflicts and optimization opportunities.

```bash
# Scan all blueprints with relationships
curl -X POST http://localhost/mcp/statamic.blueprints.scan \
  -d '{
    "include_relationships": true,
    "include_validation": true,
    "include_field_details": true
  }'

# Check for field conflicts
curl -X POST http://localhost/mcp/statamic.blueprints.field-conflicts \
  -d '{
    "namespace": "collections",
    "severity": "warning"
  }'

# Analyze field dependencies
curl -X POST http://localhost/mcp/statamic.blueprints.field-dependencies \
  -d '{
    "blueprint": "article",
    "namespace": "collections",
    "include_reverse": true
  }'
```

## Content Operations

### Batch Content Management

**Scenario**: Managing hundreds of entries efficiently.

```bash
# List all entries with filtering
curl -X POST http://localhost/mcp/statamic.entries.list \
  -d '{
    "collection": "articles",
    "status": "published",
    "limit": 50,
    "sort": "published_at",
    "order": "desc"
  }'

# Batch publish multiple entries
curl -X POST http://localhost/mcp/statamic.entries.batch_operation \
  -d '{
    "collection": "articles",
    "operation": "publish",
    "entries": ["entry-1", "entry-2", "entry-3"]
  }'

# Search across all collections
curl -X POST http://localhost/mcp/statamic.entries.search \
  -d '{
    "query": "Laravel development",
    "collections": ["articles", "tutorials"],
    "fields": ["title", "content"],
    "limit": 20
  }'
```

### Content Import/Export

**Scenario**: Migrating content between environments.

```bash
# Export entries to JSON
curl -X POST http://localhost/mcp/statamic.entries.import_export \
  -d '{
    "operation": "export",
    "collection": "articles",
    "format": "json",
    "file_path": "exports/articles.json"
  }'

# Import entries from CSV
curl -X POST http://localhost/mcp/statamic.entries.import_export \
  -d '{
    "operation": "import",
    "collection": "products",
    "format": "csv",
    "data": "name,price,description\nProduct 1,99.99,Great product"
  }'
```

### Entry Versioning and Scheduling

**Scenario**: Managing editorial workflows with versions and scheduled publishing.

```bash
# List entry versions
curl -X POST http://localhost/mcp/statamic.entries.versioning \
  -d '{
    "collection": "articles",
    "id": "article-123",
    "operation": "list"
  }'

# Schedule entry publishing
curl -X POST http://localhost/mcp/statamic.entries.scheduling_workflow \
  -d '{
    "collection": "articles",
    "id": "article-123",
    "schedule_data": {
      "publish_at": "2025-01-15T09:00:00Z",
      "unpublish_at": "2025-02-15T17:00:00Z"
    }
  }'
```

## Template Development

### Antlers Template Development

**Scenario**: Creating and validating Antlers templates with AI assistance.

```bash
# Get template hints for specific blueprint
curl -X POST http://localhost/mcp/statamic.development.antlers-validate \
  -d '{
    "template": "{{ title }}{{ author:name }}{{ content }}{{ categories }}{{ title }}{{ /categories }}",
    "blueprint": "article",
    "strict_mode": true,
    "include_suggestions": true
  }'

# Analyze template performance
curl -X POST http://localhost/mcp/statamic.templates.analyze-performance \
  -d '{
    "template_path": "resources/views/blog/show.antlers.html",
    "include_suggestions": true,
    "benchmark_runs": 10
  }'
```

**AI Response Example:**
> **Template Validation Results:**
> - ✅ `{{ title }}` - Valid field from article blueprint
> - ✅ `{{ author:name }}` - Correct relationship syntax
> - ✅ `{{ content }}` - Valid Bard field
> - ✅ `{{ categories }}{{ title }}{{ /categories }}` - Correct loop syntax
>
> **Performance Suggestions:**
> - Consider eager loading author relationship
> - Cache expensive calculations
> - Optimize image processing for featured images

### Blade Template Linting

**Scenario**: Ensuring Blade templates follow Statamic best practices.

```bash
# Lint Blade template with auto-fix suggestions
curl -X POST http://localhost/mcp/statamic.development.blade-lint \
  -d '{
    "template": "@php $entries = Entry::all(); @endphp\n<div>\n@foreach($entries as $entry)\n<h2>{{ $entry->title }}</h2>\n@endforeach\n</div>",
    "auto_fix": true,
    "policy": "strict"
  }'

# Get Blade component hints
curl -X POST http://localhost/mcp/statamic.development.blade-hints \
  -d '{
    "blueprint": "article",
    "include_components": true,
    "include_best_practices": true
  }'
```

**AI Response:**
> **Issues Found:**
> 1. **Direct Facade Usage**: Replace `Entry::all()` with `<s:collection>` tag
> 2. **Missing Security**: Escape output with `{{ }}` instead of `{!! !!}`
>
> **Auto-fix Suggestion:**
> ```blade
> <div>
>   <s:collection from="articles">
>     <h2>{{ title }}</h2>
>   </s:collection>
> </div>
> ```

### Template Variable Analysis

**Scenario**: Understanding available variables in templates.

```bash
# Extract variables from existing template
curl -X POST http://localhost/mcp/statamic.templates.extract-variables \
  -d '{
    "template_path": "resources/views/blog/index.blade.php",
    "include_dependencies": true,
    "analyze_relationships": true
  }'

# Detect unused templates
curl -X POST http://localhost/mcp/statamic.templates.detect-unused \
  -d '{
    "template_type": "antlers",
    "include_partials": true
  }'
```

## E-commerce Setup

### Complete Product Catalog System

**Scenario**: Building a comprehensive e-commerce solution.

```bash
# 1. Create product collection
curl -X POST http://localhost/mcp/statamic.collections.create \
  -d '{
    "handle": "products",
    "title": "Products",
    "blueprint": "product",
    "route": "/products/{slug}",
    "sites": ["default"]
  }'

# 2. Create product blueprint with variants
curl -X POST http://localhost/mcp/statamic.blueprints.create \
  -d '{
    "handle": "product",
    "title": "Product",
    "namespace": "collections",
    "fields": [
      {"handle": "name", "field": {"type": "text", "required": true}},
      {"handle": "price", "field": {"type": "money", "required": true}},
      {"handle": "sale_price", "field": {"type": "money"}},
      {"handle": "description", "field": {"type": "markdown"}},
      {"handle": "gallery", "field": {"type": "assets", "container": "products", "max_files": 10}},
      {"handle": "variants", "field": {
        "type": "replicator",
        "sets": {
          "variant": {
            "display": "Product Variant",
            "fields": [
              {"handle": "name", "field": {"type": "text"}},
              {"handle": "sku", "field": {"type": "text"}},
              {"handle": "price", "field": {"type": "money"}},
              {"handle": "stock", "field": {"type": "integer"}}
            ]
          }
        }
      }},
      {"handle": "categories", "field": {"type": "taxonomy", "taxonomies": ["product_categories"]}},
      {"handle": "featured", "field": {"type": "toggle"}},
      {"handle": "in_stock", "field": {"type": "toggle", "default": true}}
    ]
  }'

# 3. Create product categories taxonomy
curl -X POST http://localhost/mcp/statamic.taxonomies.create \
  -d '{
    "handle": "product_categories",
    "title": "Product Categories"
  }'

# 4. Bulk create products
curl -X POST http://localhost/mcp/statamic.entries.batch_operation \
  -d '{
    "collection": "products",
    "operation": "create",
    "data": [
      {
        "name": "Wireless Headphones",
        "price": 99.99,
        "description": "High-quality wireless headphones",
        "in_stock": true,
        "featured": true
      },
      {
        "name": "Bluetooth Speaker",
        "price": 49.99,
        "description": "Portable Bluetooth speaker",
        "in_stock": true
      }
    ]
  }'
```

## Multi-site Management

### Managing Multiple Locales

**Scenario**: Setting up and managing a multi-language website.

```bash
# Create additional site configurations
curl -X POST http://localhost/mcp/statamic.sites.create \
  -d '{
    "handle": "danish",
    "name": "Danish Site",
    "url": "https://example.dk",
    "locale": "da_DK"
  }'

curl -X POST http://localhost/mcp/statamic.sites.create \
  -d '{
    "handle": "german", 
    "name": "German Site",
    "url": "https://example.de",
    "locale": "de_DE"
  }'

# List all sites with statistics
curl -X POST http://localhost/mcp/statamic.sites.list \
  -d '{
    "include_stats": true
  }'

# Switch active site context
curl -X POST http://localhost/mcp/statamic.sites.switch \
  -d '{
    "handle": "danish"
  }'

# Get entries for specific site
curl -X POST http://localhost/mcp/statamic.entries.list \
  -d '{
    "collection": "articles",
    "site": "danish",
    "limit": 10
  }'
```

### Global Variables Management

**Scenario**: Managing site-wide settings across multiple languages.

```bash
# Create global set for site settings
curl -X POST http://localhost/mcp/statamic.globals.sets.create \
  -d '{
    "handle": "site_settings",
    "title": "Site Settings",
    "blueprint": "site_settings"
  }'

# Update global values for specific site
curl -X POST http://localhost/mcp/statamic.globals.update \
  -d '{
    "handle": "site_settings",
    "site": "danish",
    "data": {
      "site_name": "Mit Website",
      "contact_email": "kontakt@example.dk",
      "phone": "+45 12 34 56 78"
    }
  }'

# Get global values across all sites
curl -X POST http://localhost/mcp/statamic.globals.values.list \
  -d '{
    "handle": "site_settings"
  }'
```

## Form Processing

### Advanced Form Management

**Scenario**: Creating contact forms with validation and email notifications.

```bash
# Create contact form
curl -X POST http://localhost/mcp/statamic.forms.create \
  -d '{
    "handle": "contact",
    "title": "Contact Form",
    "fields": [
      {"handle": "name", "field": {"type": "text", "required": true, "display": "Full Name"}},
      {"handle": "email", "field": {"type": "email", "required": true}},
      {"handle": "subject", "field": {"type": "select", "options": ["General Inquiry", "Support", "Sales"]}},
      {"handle": "message", "field": {"type": "textarea", "required": true}},
      {"handle": "newsletter", "field": {"type": "checkboxes", "options": ["Subscribe to newsletter"]}}
    ],
    "email": {
      "to": "admin@example.com",
      "subject": "New Contact Form Submission",
      "template": "emails.contact"
    },
    "store": true
  }'

# Get form submissions with analytics
curl -X POST http://localhost/mcp/statamic.forms.submissions.stats \
  -d '{
    "form": "contact",
    "period": "month",
    "include_fields": true
  }'

# Export submissions for analysis
curl -X POST http://localhost/mcp/statamic.forms.submissions.export \
  -d '{
    "form": "contact",
    "format": "csv",
    "date_range": {
      "start": "2025-01-01",
      "end": "2025-01-31"
    }
  }'
```

## Asset Management

### Digital Asset Organization

**Scenario**: Managing thousands of images and documents efficiently.

```bash
# List assets with filtering
curl -X POST http://localhost/mcp/statamic.assets.list \
  -d '{
    "container": "main",
    "type": "image",
    "folder": "products",
    "include_meta": true,
    "limit": 50
  }'

# Batch rename assets
curl -X POST http://localhost/mcp/statamic.assets.batch_operation \
  -d '{
    "container": "main",
    "operation": "rename",
    "assets": [
      {"path": "old-image.jpg", "new_name": "product-hero-image.jpg"},
      {"path": "temp-photo.png", "new_name": "category-banner.png"}
    ]
  }'

# Move assets to organized folders
curl -X POST http://localhost/mcp/statamic.assets.move \
  -d '{
    "container": "main",
    "path": "uploads/random-image.jpg",
    "destination_container": "main", 
    "destination_path": "products/electronics/smartphone.jpg"
  }'

# Update asset metadata
curl -X POST http://localhost/mcp/statamic.assets.update \
  -d '{
    "container": "main",
    "path": "products/smartphone.jpg",
    "meta": {
      "title": "Latest Smartphone Model",
      "alt": "Black smartphone with large display",
      "description": "Product photo for e-commerce listing"
    }
  }'
```

## User Management

### Role-Based Access Control

**Scenario**: Setting up a team with different permission levels.

```bash
# Create custom roles
curl -X POST http://localhost/mcp/statamic.roles.create \
  -d '{
    "handle": "content_editor",
    "title": "Content Editor", 
    "permissions": [
      "view cp",
      "edit entries",
      "publish entries",
      "edit assets"
    ]
  }'

curl -X POST http://localhost/mcp/statamic.roles.create \
  -d '{
    "handle": "content_manager",
    "title": "Content Manager",
    "permissions": [
      "view cp",
      "edit entries",
      "publish entries", 
      "delete entries",
      "edit assets",
      "delete assets",
      "edit blueprints"
    ]
  }'

# Create users with roles
curl -X POST http://localhost/mcp/statamic.users.create \
  -d '{
    "email": "editor@example.com",
    "name": "Content Editor",
    "roles": ["content_editor"],
    "data": {
      "bio": "Responsible for blog content",
      "department": "Marketing"
    }
  }'

# Create user groups for organization
curl -X POST http://localhost/mcp/statamic.groups.create \
  -d '{
    "handle": "marketing_team",
    "title": "Marketing Team",
    "users": ["editor@example.com", "manager@example.com"]
  }'

# List users with filtering
curl -X POST http://localhost/mcp/statamic.users.list \
  -d '{
    "role": "content_editor",
    "active": true,
    "limit": 20
  }'
```

## Performance Optimization

### Cache Management and Performance Monitoring

**Scenario**: Optimizing a high-traffic Statamic website.

```bash
# Analyze current cache status
curl -X POST http://localhost/mcp/statamic.system.cache.status \
  -d '{
    "include_sizes": true,
    "include_stats": true
  }'

# Clear specific cache types
curl -X POST http://localhost/mcp/statamic.system.cache.clear \
  -d '{
    "types": ["stache", "static"],
    "force": false
  }'

# Advanced Stache management
curl -X POST http://localhost/mcp/statamic.system.stache-management \
  -d '{
    "operation": "analyze",
    "include_stats": true
  }'

# Monitor system performance
curl -X POST http://localhost/mcp/statamic.system.performance-monitor \
  -d '{
    "duration": 30,
    "include_queries": true,
    "include_cache": true
  }'

# Search index optimization
curl -X POST http://localhost/mcp/statamic.system.search-index-analyzer \
  -d '{
    "include_suggestions": true,
    "rebuild": false
  }'
```

**AI Response Example:**
> **Performance Analysis Results:**
> - **Cache Hit Rate**: 94% (excellent)
> - **Average Response Time**: 245ms
> - **Database Queries**: 12 avg per request (consider optimization)
> - **Memory Usage**: 128MB avg (within limits)
>
> **Recommendations:**
> 1. Enable static caching for anonymous users
> 2. Implement image optimization pipeline
> 3. Consider query optimization for entry relationships
> 4. Set up Redis cache for sessions

### Template Performance Analysis

**Scenario**: Identifying and fixing slow templates.

```bash
# Analyze template performance
curl -X POST http://localhost/mcp/statamic.templates.analyze-performance \
  -d '{
    "template_path": "resources/views/blog/index.blade.php",
    "include_suggestions": true,
    "benchmark_runs": 50
  }'

# Get optimization suggestions
curl -X POST http://localhost/mcp/statamic.templates.suggest-optimizations \
  -d '{
    "template_path": "resources/views/products/index.antlers.html",
    "performance_focus": true,
    "accessibility_focus": true
  }'
```

**AI Response:**
> **Template Performance Analysis:**
> - **Average Render Time**: 89ms (slow)
> - **Memory Usage**: 45MB per render
> - **Bottlenecks**: 
>   - N+1 query problem with product variants (45ms)
>   - Large image processing (28ms)
>   - Complex taxonomy queries (16ms)
>
> **Optimization Suggestions:**
> 1. **Eager Load Relationships**: Use `with` parameter in collection tag
> 2. **Image Optimization**: Implement responsive images with proper sizing
> 3. **Query Caching**: Cache taxonomy queries for 1 hour
> 4. **Pagination**: Limit products per page to 24 instead of 100

This comprehensive set of examples demonstrates how the 135 MCP tools can be used to build, manage, and optimize complex Statamic applications efficiently. Each tool provides specific functionality while working together as a cohesive system for complete CMS management.