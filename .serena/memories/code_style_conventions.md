# Code Style and Conventions

## PHP Standards
- **Strict Types**: All PHP files must declare `strict_types=1`
- **Type Safety**: Comprehensive type annotations for all methods
- **PHPDoc**: Required `@param` and `@return` annotations
- **Array Types**: Specific array shapes like `array<string, mixed>` (no `mixed` types)
- **Method Signatures**: Explicit typing for all parameters and return types

## Code Quality Requirements
- **PHPStan Level 8**: Zero errors tolerance for production
- **Laravel Pint**: Code formatting with Laravel preset
- **Pest Testing**: Comprehensive test coverage with parallel execution
- **Error Handling**: Standardized error responses and exception handling

## Naming Conventions
- **PSR-4 Autoloading**: `Cboxdk\StatamicMcp\` namespace
- **Tool Naming**: `statamic.{domain}.{action}` format
- **Router Classes**: Domain-based routing (BlueprintsRouter, ContentRouter)
- **Method Names**: Descriptive camelCase with clear purpose

## Architecture Patterns
- **Router Pattern**: Single router per domain vs 140+ individual tools
- **Concerns/Traits**: Reusable functionality (ExecutesWithAudit, RouterHelpers)
- **DTOs**: Structured response objects (SuccessResponse, ErrorResponse)
- **Base Classes**: BaseStatamicTool, BaseRouter for standardization

## Security Requirements
- **Input Validation**: Defensive validation for all arguments
- **Path Security**: PathValidator for file system operations
- **Sanitization**: Error message and input sanitization
- **Rate Limiting**: Tool execution monitoring and limits