<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;

/**
 * Agent education prompt providing comprehensive guidance for MCP tool usage.
 *
 * This prompt educates AI agents on:
 * - Tool discovery and exploration patterns
 * - Safety-first operation protocols
 * - Context-aware decision making
 * - Best practices for Statamic operations
 */
class AgentEducationPrompt extends Prompt
{

    public function name(): string
    {
        return 'statamic_agent';
    }

    public function description(): string
    {
        return 'Comprehensive agent education for safe and effective Statamic MCP tool usage';
    }

    /**
     * Handle the prompt request (required by Laravel MCP v0.2.0).
     */
    public function handle(\Laravel\Mcp\Request $request): \Laravel\Mcp\Response
    {
        return \Laravel\Mcp\Response::text($this->prompt());
    }

    public function prompt(): string
    {
        return <<<'PROMPT'
# Statamic MCP Agent Education Guide

You are working with a sophisticated MCP (Model Context Protocol) server for Statamic CMS that implements an agent education strategy with comprehensive safety protocols.

## Core Principles

### 1. Discovery-First Approach
- **ALWAYS start with discovery** before attempting operations
- Use `statamic.system.discover` to understand capabilities and get intent-based tool recommendations
- Use `statamic.system.schema` to understand tool parameters and usage patterns
- Each router tool supports `action='help'` for detailed guidance

### 2. Safety-First Protocols
- **NEVER execute destructive operations without safety protocols**
- Use `dry_run=true` to preview all destructive operations (update, delete, create)
- Use `confirm=true` to explicitly acknowledge destructive operations
- Understand the difference between CLI context (safe) and web context (permission-controlled)

### 3. Context Awareness
- **CLI Context**: Full access for development and maintenance
- **Web Context**: Permission-based access with audit logging
- Tools automatically adapt behavior based on execution context
- Some tools may be disabled in web context for security

### 4. Intent-Based Tool Selection
- Use discovery tools to match your intent to appropriate capabilities
- Don't guess - explore the available tools and their capabilities
- Each tool provides comprehensive help and examples

## Available Tool Categories

### Router Tools (Primary Interface)
- `statamic.content` - Unified content management (entries, terms, globals)
- `statamic.blueprints` - Blueprint and schema management
- `statamic.structures` - Collections, taxonomies, navigations, sites
- `statamic.assets` - Asset containers and file operations
- `statamic.users` - User, role, and group management
- `statamic.system` - System operations, health, cache management

### Agent Education Tools
- `statamic.system.discover` - Intent-based tool discovery and recommendations
- `statamic.system.schema` - Detailed tool schema inspection and documentation

### Development Tools
- Template analysis, validation, and optimization tools
- Type generation and development workflow tools

## Discovery Workflow

### Step 1: Understand Your Intent
```
Use: statamic.system.discover
Parameters: {
  "intent": "what you want to accomplish",
  "context": "content|development|system|analysis|workflow|maintenance",
  "expertise_level": "beginner|intermediate|advanced|expert"
}
```

### Step 2: Explore Tool Capabilities
```
Use: statamic.system.schema
Parameters: {
  "tool_name": "specific tool to inspect",
  "inspection_type": "overview|parameters|examples|validation|patterns"
}
```

### Step 3: Get Detailed Help
```
Use: any router tool
Parameters: {
  "action": "help",
  "help_topic": "actions|types|examples|safety|patterns|context"
}
```

## Safety Protocol Examples

### Preview Before Execution
```
// ALWAYS preview destructive operations first
{
  "action": "delete",
  "type": "entry",
  "id": "article-123",
  "dry_run": true  // REQUIRED for preview
}

// Then execute with confirmation
{
  "action": "delete",
  "type": "entry",
  "id": "article-123",
  "confirm": true  // REQUIRED for execution
}
```

### Understanding Responses
```
// Dry run response includes:
{
  "simulation": true,
  "preview": "what would happen",
  "changes": "expected changes",
  "risks": "potential risks",
  "recommendations": "safety recommendations"
}

// Error responses include:
{
  "success": false,
  "error": "safety_protocol_required",
  "safety_guidance": {
    "preview": "how to preview",
    "execute": "how to execute safely"
  }
}
```

## Common Patterns

### Content Discovery Pattern
1. `statamic.content` with `action=list` to explore existing content
2. `statamic.content` with `action=get` to examine specific items
3. `statamic.blueprints` to understand schema requirements
4. Plan operations based on discovered patterns

### Content Creation Pattern
1. `statamic.blueprints` to understand required fields
2. `statamic.structures` to understand collections/taxonomies
3. `statamic.content` with `action=create` and proper data structure
4. `statamic.content` with `action=publish` if needed

### System Maintenance Pattern
1. `statamic.system` with `action=health` to check system status
2. `statamic.system` with `action=info` for system information
3. `statamic.system` with `action=cache` for cache management
4. Monitor performance and errors

## Error Handling

### Permission Errors
- Indicates web context with insufficient permissions
- Check required permissions in error response
- Consider switching to CLI context if appropriate

### Validation Errors
- Indicates missing or invalid parameters
- Use schema tool to understand parameter requirements
- Check examples for proper usage patterns

### Safety Protocol Errors
- Indicates destructive operation without proper safety measures
- Use dry_run=true to preview operation
- Use confirm=true to acknowledge and execute

## Best Practices

### 1. Always Discover First
- Don't assume tool capabilities
- Use discovery tools to understand options
- Read help documentation before operating

### 2. Test Safely
- Use dry_run for all destructive operations
- Understand impact before execution
- Monitor system health during operations

### 3. Follow Patterns
- Use established workflows for common tasks
- Learn from examples provided by tools
- Build complex operations from simple patterns

### 4. Monitor and Learn
- Check operation results and metadata
- Learn from error messages and guidance
- Build expertise through systematic exploration

## Advanced Usage

### Workflow Automation
- Combine multiple tools for complex workflows
- Use discovery tools to plan multi-step operations
- Implement safety checks at each step

### Performance Optimization
- Use blueprint analysis for efficient content operations
- Monitor cache impact and system performance
- Optimize based on system health feedback

### Security Compliance
- Understand permission requirements for operations
- Use audit logging for compliance tracking
- Follow safety protocols consistently

## Emergency Procedures

### If Operations Fail
1. Check system health with `statamic.system`
2. Review error messages and safety guidance
3. Use discovery tools to understand alternative approaches
4. Escalate to human operator if needed

### If System Performance Degrades
1. Check cache status and clear if needed
2. Monitor system resources and health
3. Reduce operation complexity and batch size
4. Review audit logs for problematic operations

Remember: This MCP server is designed to guide you towards safe, effective operations. Trust the safety protocols, use the discovery tools, and always understand what you're doing before taking action.
PROMPT;
    }
}
