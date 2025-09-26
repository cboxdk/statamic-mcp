<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;

/**
 * Tool usage contract prompt defining operational agreements for MCP tools.
 *
 * This prompt establishes:
 * - Operational contracts and expectations
 * - Safety protocol requirements
 * - Error handling and recovery procedures
 * - Performance and reliability standards
 */
class ToolUsageContractPrompt extends Prompt
{
    public function name(): string
    {
        return 'statamic_contract';
    }

    public function description(): string
    {
        return 'Tool usage contract defining operational agreements and safety protocols for Statamic MCP tools';
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
# Statamic MCP Tool Usage Contract

This contract defines the operational agreements between AI agents and the Statamic MCP server to ensure safe, reliable, and effective tool usage.

## Operational Contract

### Agent Responsibilities

#### 1. Discovery Protocol Compliance
- **MUST** use discovery tools before attempting unknown operations
- **MUST** read tool documentation and help before first use
- **MUST** understand tool capabilities and limitations
- **MUST** verify parameter requirements using schema tools

#### 2. Safety Protocol Adherence
- **MUST** use `dry_run=true` for all destructive operations before execution
- **MUST** use `confirm=true` for destructive operations after dry run validation
- **MUST** understand the difference between CLI and web execution contexts
- **MUST** respect permission boundaries in web context

#### 3. Error Handling Requirements
- **MUST** read and understand error messages completely
- **MUST** follow safety guidance provided in error responses
- **MUST** use help systems when encountering unfamiliar errors
- **MUST** not bypass safety mechanisms or ignore validation errors

#### 4. Operational Standards
- **MUST** monitor system health during complex operations
- **MUST** use appropriate tools for specific tasks (don't force wrong tools)
- **MUST** respect rate limits and performance considerations
- **MUST** maintain audit trail awareness in web context

### Server Guarantees

#### 1. Safety Guarantees
- **WILL** prevent destructive operations without proper safety protocols
- **WILL** provide comprehensive dry run simulation for all destructive operations
- **WILL** maintain audit logging for all operations in web context
- **WILL** enforce permission boundaries consistently

#### 2. Discovery Guarantees
- **WILL** provide intent-based tool recommendations
- **WILL** maintain comprehensive help systems for all tools
- **WILL** provide detailed schema information for all parameters
- **WILL** offer contextual guidance based on operation type

#### 3. Performance Guarantees
- **WILL** provide performance monitoring and health checks
- **WILL** manage cache invalidation automatically
- **WILL** optimize operations for system resources
- **WILL** provide clear performance impact information

#### 4. Error Recovery Guarantees
- **WILL** provide actionable error messages with specific guidance
- **WILL** suggest alternative approaches when operations fail
- **WILL** maintain system stability during error conditions
- **WILL** provide rollback information where applicable

## Safety Protocol Requirements

### Pre-Operation Checks
```
1. Discovery Check:
   - Have I used discovery tools to understand this operation?
   - Do I understand the tool's capabilities and limitations?
   - Am I using the right tool for this task?

2. Permission Check:
   - Do I have the required permissions for this operation?
   - Am I in the appropriate execution context (CLI vs web)?
   - Are there any security restrictions I need to consider?

3. Impact Assessment:
   - Is this operation destructive or potentially harmful?
   - What are the potential side effects and dependencies?
   - Do I need to create backups or prepare rollback plans?
```

### Execution Protocol
```
1. For Non-Destructive Operations:
   - Execute directly with appropriate parameters
   - Monitor for unexpected errors or warnings
   - Validate results and check for system impact

2. For Destructive Operations:
   - STEP 1: Execute with dry_run=true
   - STEP 2: Review simulation results, changes, and risks
   - STEP 3: If acceptable, execute with confirm=true
   - STEP 4: Validate successful completion and system health
```

### Error Recovery Protocol
```
1. Immediate Response:
   - Stop current operation if safe to do so
   - Read error message and safety guidance completely
   - Check system health if operation was system-level

2. Analysis Phase:
   - Use help tools to understand the error context
   - Review operation parameters and requirements
   - Identify root cause and corrective actions

3. Recovery Actions:
   - Follow specific guidance provided in error response
   - Use discovery tools to find alternative approaches
   - Test recovery with dry_run if applicable
   - Escalate to human operator if unable to resolve
```

## Performance Standards

### Response Time Expectations
- Discovery operations: < 2 seconds
- Read operations: < 5 seconds
- Create/Update operations: < 10 seconds
- System operations: < 15 seconds

### Resource Usage Limits
- Maximum concurrent operations: 5
- Memory usage monitoring: automatic
- Cache impact consideration: required
- System health monitoring: continuous

### Error Rate Tolerance
- Acceptable error rate: < 5% for valid operations
- Recovery time: < 30 seconds for most errors
- System stability: maintained during all error conditions

## Context-Specific Agreements

### CLI Context Operations
- Full tool access with minimal restrictions
- Direct file system and database access where appropriate
- Automatic cache management and system optimization
- Comprehensive logging and debugging information

### Web Context Operations
- Permission-based access control enforcement
- Audit logging for all operations
- Rate limiting and resource protection
- Enhanced security validation and checks

## Compliance Requirements

### Security Compliance
- All operations must respect Statamic permission system
- Sensitive data must be handled according to privacy standards
- Audit trails must be maintained for regulatory compliance
- Security vulnerabilities must be reported and addressed

### Performance Compliance
- Operations must not degrade system performance below acceptable levels
- Resource usage must be monitored and optimized
- Cache management must be performed automatically
- System health must be maintained throughout operations

### Documentation Compliance
- All operations must be properly documented in audit logs
- Error conditions must be reported with sufficient detail
- Recovery procedures must be documented and followed
- Best practices must be maintained and updated

## Contract Violations

### Agent Violations
- Bypassing safety protocols → Operation termination
- Ignoring error guidance → Escalation to human operator
- Misusing tools → Additional discovery requirements
- Performance abuse → Rate limiting enforcement

### Server Violations
- Inadequate safety measures → Emergency shutdown procedures
- Missing documentation → Help system enhancement required
- Performance degradation → System optimization mandatory
- Security failures → Immediate security review and updates

## Agreement Acceptance

By using these MCP tools, agents agree to:
1. Follow all safety protocols without exception
2. Use discovery tools before attempting unfamiliar operations
3. Respect system boundaries and limitations
4. Maintain professional standards in all operations
5. Report issues and contribute to system improvement

The server commits to:
1. Maintaining comprehensive safety measures
2. Providing accurate and helpful documentation
3. Ensuring system stability and performance
4. Supporting agent learning and development
5. Continuous improvement of tools and processes

## Emergency Contacts

### System Issues
- Check `statamic.system` health and status tools
- Review audit logs for recent operation impact
- Use discovery tools to find alternative approaches

### Security Concerns
- Immediately cease operations if security breach suspected
- Document all relevant operation details
- Escalate to human administrator
- Follow incident response procedures

### Performance Problems
- Monitor system resources using system tools
- Reduce operation complexity and frequency
- Check cache status and clear if necessary
- Report persistent issues for optimization

This contract ensures safe, effective, and reliable operation of the Statamic MCP server while maintaining system integrity and security.
PROMPT;
    }
}
