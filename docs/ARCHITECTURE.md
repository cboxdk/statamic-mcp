# Architecture Documentation

## Overview

The Statamic MCP Server is a comprehensive Model Context Protocol (MCP) implementation that provides AI assistants with structured access to Statamic CMS functionality. It follows a clean, single-purpose tool architecture with strict separation of concerns.

## Core Principles

### 1. Single-Purpose Tool Pattern
Each MCP tool performs exactly ONE action with a focused responsibility:
- **No action conditionals**: No `action` parameter with switch statements
- **Clear naming**: `statamic.{domain}.{action}` convention
- **Predictable schemas**: Each tool has a specific input/output contract
- **Better performance**: Reduced token overhead for AI assistants
- **Easier testing**: Isolated, testable components

### 2. Domain-Driven Organization
Tools are organized by Statamic domains:
- **Blueprints**: Schema and field definitions
- **Collections**: Content structure management
- **Entries**: Content CRUD operations
- **Taxonomies**: Category and tagging systems
- **Globals**: Site-wide settings and values
- **Users & Roles**: Authentication and permissions
- **System**: Cache, health, and monitoring
- **Development**: Templates, optimization, and debugging

### 3. Security First
- **Path traversal protection**: PathValidator class validates all file operations
- **Input sanitization**: Sensitive data redacted from logs
- **Structured error handling**: ErrorCodes enum for consistent errors
- **Type safety**: PHPStan Level 8 compliance with strict typing

## Directory Structure

```
src/
├── Mcp/
│   ├── Tools/                      # All MCP tool implementations
│   │   ├── BaseStatamicTool.php   # Abstract base class for all tools
│   │   ├── Blueprints/             # Blueprint management tools
│   │   ├── Collections/            # Collection structure tools
│   │   ├── Entries/                # Entry CRUD tools
│   │   ├── Taxonomies/             # Taxonomy management tools
│   │   ├── Terms/                  # Term CRUD tools
│   │   ├── Globals/                # Global sets and values tools
│   │   ├── Users/                  # User management tools
│   │   ├── Roles/                  # Role and permission tools
│   │   ├── Sites/                  # Multi-site management tools
│   │   ├── Development/            # Template and development tools
│   │   └── System/                 # System management tools
│   │
│   ├── Support/                    # Support classes
│   │   ├── ErrorCodes.php         # Standardized error codes enum
│   │   ├── ToolResponse.php       # Response builder for consistency
│   │   ├── ToolLogger.php         # Structured logging with correlation IDs
│   │   └── ToolCache.php          # Smart caching with dependency tracking
│   │
│   ├── Security/                   # Security utilities
│   │   └── PathValidator.php      # Path traversal protection
│   │
│   ├── Services/                   # Shared services
│   │   └── SchemaIntrospectionService.php  # Schema extraction
│   │
│   └── DataTransferObjects/        # DTOs for type safety
│       ├── SuccessResponse.php
│       ├── ErrorResponse.php
│       └── ResponseMeta.php
│
├── ServiceProvider.php             # Laravel service provider
└── config/
    └── statamic_mcp.php           # Configuration file
```

## Key Components

### BaseStatamicTool

Abstract base class that all tools extend. Provides:
- Standardized error handling
- Structured logging with correlation IDs
- Response formatting
- Helper methods for common operations
- Type-safe argument handling

```php
abstract class BaseStatamicTool extends Tool
{
    abstract protected function getToolName(): string;
    abstract protected function getToolDescription(): string;
    abstract protected function defineSchema(ToolInputSchema $schema): ToolInputSchema;
    abstract protected function execute(array $arguments): array;
    
    // Standardized handle method with logging and error handling
    final public function handle(array $arguments): ToolResult
    {
        $startTime = microtime(true);
        $correlationId = ToolLogger::toolStarted($this->getToolName(), $arguments);
        
        try {
            $result = $this->execute($arguments);
            ToolLogger::toolSuccess($this->getToolName(), $correlationId, microtime(true) - $startTime);
            return ToolResult::json($this->wrapInStandardFormat($result));
        } catch (\Exception $e) {
            ToolLogger::toolFailed($this->getToolName(), $correlationId, $e);
            return ToolResult::json($this->createErrorResponse($e->getMessage())->toArray());
        }
    }
}
```

### Error Handling

Comprehensive error handling with standardized codes:

```php
enum ErrorCodes: string
{
    // Validation errors
    case INVALID_INPUT = 'INVALID_INPUT';
    case MISSING_REQUIRED_FIELD = 'MISSING_REQUIRED_FIELD';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    
    // Security errors
    case PATH_TRAVERSAL = 'PATH_TRAVERSAL';
    case UNAUTHORIZED = 'UNAUTHORIZED';
    case PERMISSION_DENIED = 'PERMISSION_DENIED';
    
    // Resource errors
    case RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    case RESOURCE_ALREADY_EXISTS = 'RESOURCE_ALREADY_EXISTS';
    
    // Operation errors
    case OPERATION_FAILED = 'OPERATION_FAILED';
    case CACHE_ERROR = 'CACHE_ERROR';
}
```

### Caching Strategy

Smart caching with dependency tracking:
- **Discovery operations**: Cached with file modification tracking
- **Blueprint scanning**: Invalidated on file changes
- **Expensive operations**: Configurable TTL with automatic invalidation

```php
// Cache discovery with dependencies
$cached = ToolCache::getCachedDiscovery($toolName, [__DIR__ . '/../../Tools']);
if ($cached !== null) {
    return $cached;
}

// ... perform expensive operation ...

return ToolCache::cacheDiscovery($toolName, $result, $dependencies);
```

### Security Measures

#### Path Traversal Protection
```php
// Validate all file paths
$validPath = PathValidator::validatePath($path, PathValidator::getAllowedTemplatePaths());

// Check for suspicious patterns
if (PathValidator::containsSuspiciousPatterns($path)) {
    throw new InvalidArgumentException('Invalid path');
}
```

#### Input Sanitization
```php
// Automatically sanitize sensitive data in logs
ToolLogger::toolStarted($toolName, $arguments); // Passwords, tokens auto-redacted
```

## Response Format

All tools return a consistent response structure:

```json
{
  "success": true,
  "data": {
    // Tool-specific data
  },
  "meta": {
    "tool": "statamic.blueprints.list",
    "timestamp": "2025-01-01T12:00:00Z",
    "statamic_version": "v5.46",
    "laravel_version": "12.0"
  },
  "errors": [],
  "warnings": []
}
```

## Performance Optimizations

### 1. Pagination Support
All listing tools support pagination:
```php
$limit = $arguments['limit'] ?? 50;
$offset = $arguments['offset'] ?? 0;
```

### 2. Field Filtering
Blueprint scanning can exclude field details:
```php
$includeFields = $arguments['include_fields'] ?? true;
```

### 3. Response Size Limits
Automatic truncation to prevent token overflow:
```php
if (strlen($response) > 25000) {
    $response = substr($response, 0, 25000) . '... [truncated]';
}
```

### 4. Smart Caching
- Discovery operations cached for 1 hour
- Blueprint scans cached for 30 minutes
- Cache invalidated on file changes

## Testing Strategy

### Unit Tests
- Each tool has dedicated test coverage
- Mock Statamic facades for isolation
- Test error conditions and edge cases

### Integration Tests
- Test with real Statamic installation
- Verify cache behavior
- Test multi-site configurations

### Quality Standards
- PHPStan Level 8 compliance
- Laravel Pint formatting
- 92+ passing tests
- Strict type declarations

## Development Workflow

### Creating a New Tool

1. **Create tool class** extending BaseStatamicTool:
```php
class MyNewTool extends BaseStatamicTool
{
    protected function getToolName(): string {
        return 'statamic.domain.action';
    }
    
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema {
        return $schema
            ->string('parameter')
            ->description('Parameter description')
            ->required();
    }
    
    protected function execute(array $arguments): array {
        // Tool logic here
        return ['result' => 'data'];
    }
}
```

2. **Add test coverage**:
```php
test('my new tool works correctly', function () {
    $tool = new MyNewTool();
    $result = $tool->handle(['parameter' => 'value']);
    expect($result)->toBeInstanceOf(ToolResult::class);
});
```

3. **Run quality checks**:
```bash
composer quality  # Runs pint + stan + test
```

## Configuration

The addon uses `config/statamic_mcp.php` for configuration:

```php
return [
    'cache' => [
        'enabled' => true,
        'ttl' => [
            'discovery' => 3600,
            'blueprint_scan' => 1800,
            'general' => 300,
        ],
    ],
    'logging' => [
        'enabled' => true,
        'correlation_ids' => true,
        'performance_warnings' => true,
    ],
    'security' => [
        'path_validation' => true,
        'input_sanitization' => true,
    ],
];
```

## Best Practices

1. **Tool Naming**: Follow `statamic.{domain}.{action}` convention
2. **Error Messages**: Provide helpful, actionable error messages
3. **Type Safety**: Use strict types and PHPDoc annotations
4. **Logging**: Include correlation IDs for debugging
5. **Caching**: Cache expensive operations with proper invalidation
6. **Security**: Validate all input and file paths
7. **Testing**: Write tests for success and failure cases
8. **Documentation**: Update docs when adding new tools

## Integration with Laravel MCP

The addon automatically registers with Laravel's MCP server through the ServiceProvider:

```php
public function bootAddon()
{
    // Tools are auto-discovered from the Tools directory
    // Each tool is registered with the MCP server
    // The server is available via: php artisan mcp:serve statamic
}
```

## Future Considerations

- **GraphQL Support**: Potential GraphQL query tools
- **Webhook Integration**: Real-time event notifications
- **Batch Operations**: Bulk content management
- **Custom Field Types**: Support for third-party field types
- **Performance Profiling**: Built-in profiling tools