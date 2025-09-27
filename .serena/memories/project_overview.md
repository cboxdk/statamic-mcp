# Statamic MCP Server Project Overview

## Purpose
A Statamic addon that functions as an MCP (Model Context Protocol) server, built on top of Laravel's MCP server. The addon extends Statamic CMS v5 and provides AI-powered development tools for Statamic CMS through 100+ MCP tools for blueprints, entries, collections, and more.

## Tech Stack
- **Framework**: Laravel/Statamic CMS v5
- **MCP Implementation**: Laravel MCP v0.2.0
- **Language**: PHP 8.1+ with strict types
- **Testing**: Pest 4.0 with Laravel plugin
- **Code Quality**: PHPStan Level 8, Laravel Pint formatting
- **Dependencies**: Symfony YAML for configuration parsing

## Key Features
- Router-based MCP tool architecture (evolved from 140+ single tools)
- Web-accessible MCP endpoints for browser integrations
- Comprehensive error handling and logging
- Dry-run support for destructive operations
- Standardized response formats with metadata
- Performance monitoring and rate limiting
- Security validation and audit trails

## Architecture
- **Service Provider**: Main entry point extending Statamic AddonServiceProvider
- **Router Pattern**: Domain-based tool routing (blueprints, content, users, etc.)
- **Base Classes**: BaseStatamicTool and BaseRouter for standardization
- **Response DTOs**: Structured response objects with metadata
- **Concerns**: Reusable traits for common functionality
- **Security**: Path validation, authentication middleware