# Task Completion Checklist

## Before Committing Changes
1. **Code Formatting**: Run `composer pint` to format all code
2. **Static Analysis**: Ensure `composer stan` passes with zero errors
3. **Test Suite**: Verify `composer test` passes all tests
4. **Quality Pipeline**: Run `composer quality` for complete validation

## Code Quality Gates
- **PHPStan Level 8**: Must pass without errors or baseline additions
- **Type Safety**: All methods must have proper type annotations
- **Strict Types**: All PHP files must declare `strict_types=1`
- **No Mixed Types**: Use specific array shapes instead of `mixed`

## MCP Tool Requirements
- **Router Pattern**: Use domain routers instead of single-purpose tools
- **Error Handling**: Standardized error responses with correlation IDs
- **Dry Run Support**: For destructive operations
- **Schema Validation**: Proper JsonSchema definitions
- **Documentation**: Clear action descriptions and examples

## Testing Requirements
- **Test Coverage**: New tools must have corresponding tests
- **Integration Tests**: Router functionality testing
- **Fixtures**: Use existing test fixtures for consistency
- **Parallel Safe**: Tests must work with `--parallel` flag

## Production Readiness
- **Security**: Input validation and sanitization
- **Performance**: Tool execution monitoring
- **Logging**: Proper audit trails and error logging
- **Response Format**: Standardized success/error responses