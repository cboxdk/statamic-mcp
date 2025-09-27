# Essential Development Commands

## Testing Commands
```bash
# Run all tests with Pest (parallel execution)
./vendor/bin/pest
composer test

# Run tests with coverage analysis
./vendor/bin/pest --coverage
composer test:coverage

# Run specific test file
./vendor/bin/pest tests/Feature/Routers/BlueprintsRouterTest.php

# Watch mode for continuous testing
./vendor/bin/pest --watch
```

## Code Quality Commands
```bash
# Format code with Laravel Pint
./vendor/bin/pint
composer pint

# Check formatting without making changes
./vendor/bin/pint --test
composer pint:test

# Run PHPStan static analysis (Level 8)
./vendor/bin/phpstan analyse
composer stan

# Complete quality check pipeline
composer quality  # Runs: pint + stan + test
```

## Development Workflow
```bash
# Install dependencies
composer install

# Update dependencies  
composer update

# Publish configuration
php artisan vendor:publish --tag=statamic-mcp-config

# Install the addon
php artisan statamic:install-mcp
```

## System Commands (macOS)
```bash
# File operations
ls -la          # List files with permissions
find . -name    # Find files by name
grep -r         # Recursive text search
cd              # Change directory

# Git operations
git status      # Check repository status
git diff        # Show changes
git branch      # List branches
```