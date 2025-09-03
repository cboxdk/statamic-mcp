# AI Assistant Setup Guide

This guide covers setup instructions for all major AI assistants that support MCP (Model Context Protocol).

## Claude Code (Anthropic) - **Recommended**

Claude Code has the best MCP integration and is the primary recommended assistant for this project.

### Setup

Add to your Claude Code MCP configuration file:

**macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
**Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
**Linux**: `~/.config/claude/claude_desktop_config.json`

```json
{
  "mcpServers": {
    "statamic": {
      "command": "php",
      "args": [
        "/absolute/path/to/your/project/artisan",
        "mcp:serve",
        "statamic"
      ],
      "env": {
        "APP_ENV": "local"
      }
    }
  }
}
```

### Usage

Once configured, Claude Code automatically detects the MCP tools. You can ask:

- "What Statamic tools are available?"
- "Show me my blueprint structures"
- "Search for documentation about collections"
- "Validate this Antlers template"

## Cursor Editor

Cursor has experimental MCP support through extensions.

### Setup

1. Add to your `.cursorrules` file in your project root:

```markdown
# Statamic MCP Server Integration

This project uses Statamic MCP Server for enhanced development experience.

MCP Server Command: php artisan mcp:serve statamic

Available tools:
- statamic.system.info: Get Statamic installation information
- statamic.blueprints.scan: Analyze blueprint structures
- statamic.docs.search: Search Statamic documentation
- statamic.addons.scan: Scan installed addons
- statamic.antlers.hints: Get Antlers template assistance
- statamic.blade.lint: Lint Blade templates

When working with Statamic, use these MCP tools to understand the project structure.
```

2. Configure Cursor's MCP settings in VS Code settings (`settings.json`):

```json
{
  "cursor.mcpServers": {
    "statamic": {
      "command": "php",
      "args": ["/absolute/path/to/your/project/artisan", "mcp:serve", "statamic"],
      "cwd": "/absolute/path/to/your/project"
    }
  }
}
```

## Cline (VS Code Extension)

Cline supports MCP through VS Code settings.

### Setup

In VS Code settings (`settings.json`):

```json
{
  "cline.mcpServers": {
    "statamic": {
      "command": "php",
      "args": [
        "/absolute/path/to/your/project/artisan",
        "mcp:serve", 
        "statamic"
      ],
      "env": {
        "APP_ENV": "local",
        "PATH": "/usr/local/bin:/usr/bin:/bin"
      },
      "cwd": "/absolute/path/to/your/project"
    }
  }
}
```

### Usage

- Start Cline in VS Code
- The MCP server will be automatically available
- Ask about Statamic-specific functionality

## GitHub Copilot

GitHub Copilot doesn't directly support MCP, but you can enhance its understanding with context files.

### Setup

Create `.github/copilot-instructions.md`:

```markdown
# Statamic Project Context

This project uses Statamic CMS with an MCP server for enhanced development.

## System Information
Use the statamic.system.info tool to understand:
- Statamic version and edition (Pro/Solo)  
- Storage type (file-based, database, mixed)
- Cache configuration and multi-site setup
- Available features and configuration

## Available MCP Tools
- statamic.system.info: Statamic installation analysis
- statamic.blueprints.scan: Blueprint structure analysis
- statamic.addons.scan: Installed addon discovery
- statamic.docs.search: Documentation search
- statamic.fieldtypes.list: Field type exploration
- statamic.tags.scan: Available Blade tags
- statamic.antlers.hints: Template assistance
- statamic.antlers.validate: Template validation
- statamic.blade.hints: Blade suggestions
- statamic.blade.lint: Blade linting
- statamic.blueprints.types: Type generation

## Statamic Development Patterns
- Use Statamic Blade tags: `<s:collection>`, `<s:form:create>`
- Avoid direct facade calls in views
- Prefer blueprint-driven content structure
- Use Antlers for simple templating, Blade for complex logic
- Follow Statamic naming conventions

## Field Type Categories
- Text: text, textarea, markdown, code
- Rich Content: bard, redactor
- Media: assets, video  
- Relationships: entries, taxonomy, users
- Structured: replicator, grid, group, yaml

When suggesting Statamic implementations, reference these tools and patterns.
```

## Junie (AI Assistant)

Junie supports MCP through configuration files.

### Setup

Create `.junie/config.yml`:

```yaml
mcp_servers:
  statamic:
    command: php
    args:
      - "./artisan"
      - "mcp:serve" 
      - "statamic"
    working_directory: "."
    environment:
      APP_ENV: local
    
tools:
  - name: "System Information"
    command: "statamic.system.info"
    description: "Get comprehensive Statamic installation information"
    
  - name: "Analyze Blueprints"
    command: "statamic.blueprints.scan"
    description: "Scan and analyze Statamic blueprint structures"
    
  - name: "Search Documentation"
    command: "statamic.docs.search"
    description: "Search Statamic documentation on any topic"
    
  - name: "Scan Addons"
    command: "statamic.addons.scan"
    description: "Discover installed Statamic addons and resources"
    
  - name: "Generate Types" 
    command: "statamic.blueprints.types"
    description: "Generate TypeScript, PHP, or JSON types from blueprints"
    
  - name: "Explore Field Types"
    command: "statamic.fieldtypes.list" 
    description: "List available field types with configuration examples"
    
  - name: "Scan Statamic Tags"
    command: "statamic.tags.scan"
    description: "Discover all available Statamic Blade tags and parameters"
    
  - name: "Antlers Hints"
    command: "statamic.antlers.hints"
    description: "Get context-aware hints for Antlers templates"
    
  - name: "Validate Antlers"
    command: "statamic.antlers.validate"
    description: "Validate Antlers template syntax against blueprints"
    
  - name: "Blade Hints"
    command: "statamic.blade.hints" 
    description: "Get Statamic-specific Blade component suggestions"
    
  - name: "Lint Blade"
    command: "statamic.blade.lint"
    description: "Lint Blade templates with policy enforcement"
```

## Continue (VS Code Extension)

Continue.dev supports MCP through configuration.

### Setup

In your `~/.continue/config.json`:

```json
{
  "models": [
    {
      "title": "Claude 3.5 Sonnet",
      "provider": "anthropic",
      "model": "claude-3-5-sonnet-20241022"
    }
  ],
  "mcpServers": {
    "statamic": {
      "command": "php",
      "args": [
        "/absolute/path/to/your/project/artisan",
        "mcp:serve",
        "statamic"  
      ],
      "env": {
        "APP_ENV": "local"
      }
    }
  }
}
```

## General Tips

### Path Configuration
- Always use **absolute paths** in MCP configurations
- Test your paths: `php /absolute/path/to/your/project/artisan mcp:serve statamic`
- Ensure proper permissions for PHP and Laravel

### Environment Variables
- Set `APP_ENV=local` for development
- Include necessary PATH variables for PHP access
- Consider database and cache configuration

### Troubleshooting
1. **Server not starting**: Check PHP path and Laravel installation
2. **Tools not found**: Ensure `composer require --dev cboxdk/statamic-mcp` is installed
3. **Permissions**: Verify file system permissions for Laravel storage
4. **Port conflicts**: Each AI assistant manages its own MCP connection

### Testing Your Setup

Run this command to test your MCP server:

```bash
php /absolute/path/to/your/project/artisan mcp:serve statamic
```

You should see output confirming the server started with 11 available tools.