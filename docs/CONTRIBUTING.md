# Contributing Guide

Thank you for your interest in contributing to the Statamic MCP Server! This guide will help you get started with development and ensure your contributions meet our standards.

## Getting Started

### Prerequisites
- PHP 8.2 or higher
- Composer 2.x
- Laravel 11+
- Statamic 5.0+
- Git

### Development Setup

1. **Fork and Clone**
   ```bash
   git clone https://github.com/YOUR_USERNAME/statamic-mcp.git
   cd statamic-mcp
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Link for Local Development**
   ```bash
   # In your Statamic project
   composer config repositories.statamic-mcp path ../path/to/statamic-mcp
   composer require cboxdk/statamic-mcp:@dev
   ```

## Development Workflow

### Creating a New Tool

1. **Follow the Single-Purpose Pattern**
   Each tool should perform exactly ONE action:
   ```php
   // ✅ Good: Single purpose
   class ListEntriesTool extends BaseStatamicTool
   {
       protected function getToolName(): string {
           return 'statamic.entries.list';
       }
   }
   
   // ❌ Bad: Multiple actions
   class ManageEntriesTool extends BaseStatamicTool
   {
       // Don't use action parameters!
   }
   ```

2. **Create Tool Class**
   ```bash
   # Create in appropriate category folder
   touch src/Mcp/Tools/YourCategory/YourActionTool.php
   ```

3. **Implement Required Methods**
   ```php
   <?php
   
   declare(strict_types=1);
   
   namespace Cboxdk\StatamicMcp\Mcp\Tools\YourCategory;
   
   use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
   use Laravel\Mcp\Server\Tools\ToolInputSchema;
   
   class YourActionTool extends BaseStatamicTool
   {
       protected function getToolName(): string
       {
           return 'statamic.category.action';
       }
       
       protected function getToolDescription(): string
       {
           return 'Clear, concise description of what this tool does';
       }
       
       protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
       {
           return $schema
               ->string('parameter')
               ->description('Parameter description')
               ->required()
               ->boolean('optional_flag')
               ->description('Optional parameter')
               ->optional();
       }
       
       protected function execute(array $arguments): array
       {
           // Validate input
           $this->validateRequiredArguments($arguments, ['parameter']);
           
           // Perform operation
           try {
               // Your tool logic here
               $result = $this->performOperation($arguments);
               
               return [
                   'success' => true,
                   'data' => $result,
                   'meta' => [
                       'processed' => count($result),
                   ],
               ];
           } catch (\Exception $e) {
               throw new \RuntimeException(
                   'Operation failed: ' . $e->getMessage()
               );
           }
       }
   }
   ```

4. **Add Test Coverage**
   ```bash
   touch tests/Tools/YourCategory/YourActionToolTest.php
   ```

   ```php
   <?php
   
   use Cboxdk\StatamicMcp\Mcp\Tools\YourCategory\YourActionTool;
   
   test('your action tool works correctly', function () {
       $tool = new YourActionTool();
       
       $result = $tool->handle([
           'parameter' => 'test_value',
       ]);
       
       expect($result)->toBeInstanceOf(ToolResult::class);
       
       $data = json_decode($result->output, true);
       expect($data)->toHaveKey('success', true);
       expect($data['data'])->toBeArray();
   });
   
   test('your action tool validates required parameters', function () {
       $tool = new YourActionTool();
       
       expect(fn() => $tool->handle([]))
           ->toThrow(\InvalidArgumentException::class);
   });
   ```

## Code Standards

### PHPStan Level 8
All code must pass PHPStan Level 8 analysis:
```bash
composer stan
```

Common requirements:
- All methods must have parameter and return type declarations
- Use PHPDoc for array shapes: `@param array<string, mixed> $data`
- Avoid `mixed` types where possible
- Add `declare(strict_types=1);` to all PHP files

### Laravel Pint
Code must be formatted with Laravel Pint:
```bash
composer pint
```

### Testing
All new features must have test coverage:
```bash
composer test
```

Run specific tests:
```bash
./vendor/bin/pest tests/YourTest.php
```

### Quality Check
Before committing, run the complete quality suite:
```bash
composer quality
```

This runs:
1. Laravel Pint (formatting)
2. PHPStan (static analysis)
3. Pest (tests)

## Naming Conventions

### Tool Names
Follow the pattern: `statamic.{domain}.{action}`

Examples:
- `statamic.entries.list`
- `statamic.blueprints.create`
- `statamic.users.delete`

### Class Names
- Tools: `{Action}{Domain}Tool.php`
  - Example: `ListEntriesTool.php`
- Tests: `{ClassName}Test.php`
  - Example: `ListEntriesToolTest.php`

### Method Names
- Use descriptive names: `validateBlueprintStructure()` not `validate()`
- Boolean methods: `isPublished()`, `hasPermission()`
- Getters: `getUserRole()` not `role()`

## Error Handling

### Use Error Codes
```php
use Cboxdk\StatamicMcp\Mcp\Support\ErrorCodes;

throw new \InvalidArgumentException(
    ErrorCodes::RESOURCE_NOT_FOUND->getMessage()
);
```

### Provide Helpful Messages
```php
if (!$entry) {
    throw new \RuntimeException(
        "Entry not found with ID: {$id}. " .
        "Available entries: " . implode(', ', $availableIds)
    );
}
```

## Security

### Path Validation
Always validate file paths:
```php
use Cboxdk\StatamicMcp\Mcp\Security\PathValidator;

$validPath = PathValidator::validatePath(
    $path,
    PathValidator::getAllowedTemplatePaths()
);
```

### Input Sanitization
Sensitive data is automatically sanitized in logs:
```php
// Passwords, tokens, etc. are auto-redacted
ToolLogger::toolStarted($toolName, $arguments);
```

## Performance

### Use Caching
For expensive operations:
```php
use Cboxdk\StatamicMcp\Mcp\Support\ToolCache;

$cached = ToolCache::getCachedDiscovery($toolName, $dependencies);
if ($cached !== null) {
    return $cached;
}

// Perform operation...

return ToolCache::cacheDiscovery($toolName, $result, $dependencies);
```

### Pagination Support
Always support pagination for list operations:
```php
$limit = $arguments['limit'] ?? 50;
$offset = $arguments['offset'] ?? 0;

$items = collect($allItems)
    ->slice($offset, $limit)
    ->values();
```

## Documentation

### Tool Documentation
Each tool should have clear documentation:
- Purpose and use case
- All parameters with types and descriptions
- Example usage
- Possible error conditions

### Code Comments
- Use PHPDoc for all public methods
- Explain complex logic inline
- Document edge cases and workarounds

## Pull Request Process

1. **Create Feature Branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make Changes**
   - Follow all code standards
   - Add tests for new features
   - Update documentation

3. **Run Quality Checks**
   ```bash
   composer quality
   ```

4. **Commit Changes**
   ```bash
   git add .
   git commit -m "feat: add new feature description"
   ```

   Follow conventional commits:
   - `feat:` New feature
   - `fix:` Bug fix
   - `docs:` Documentation
   - `style:` Formatting
   - `refactor:` Code refactoring
   - `test:` Tests
   - `chore:` Maintenance

5. **Push and Create PR**
   ```bash
   git push origin feature/your-feature-name
   ```

6. **PR Description**
   Include:
   - What changes were made
   - Why they were needed
   - How to test them
   - Related issues

## Review Process

Your PR will be reviewed for:
1. **Functionality**: Does it work as intended?
2. **Code Quality**: PHPStan Level 8, Pint formatting
3. **Tests**: Adequate coverage, passing tests
4. **Documentation**: Updated where needed
5. **Architecture**: Follows single-purpose pattern
6. **Security**: No vulnerabilities introduced
7. **Performance**: No unnecessary overhead

## Getting Help

- **Issues**: Open a GitHub issue for bugs or features
- **Discussions**: Use GitHub Discussions for questions
- **Documentation**: Check `/docs` folder
- **Examples**: See existing tools for patterns

## License

By contributing, you agree that your contributions will be licensed under the MIT License.