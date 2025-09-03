# Template Optimization Guide

This guide covers the advanced template analysis and optimization capabilities provided by the OptimizedTemplateAnalyzer and integrated development tools.

## Overview

The OptimizedTemplateAnalyzer provides comprehensive analysis of both Antlers and Blade templates, detecting performance issues, security vulnerabilities, and edge cases that could impact production deployments.

## Integration Points

### Antlers Template Validation
**Tool**: `statamic.development.antlers-validate`

Enhanced with performance analysis and edge case detection:
```json
{
  "tool": "statamic.development.antlers-validate",
  "arguments": {
    "template": "{{ collection:blog limit=\"100\" }}{{ title }}{{ /collection:blog }}",
    "blueprint": "article",
    "context": "entry",
    "strict_mode": true,
    "performance_analysis": true
  }
}
```

### Blade Template Linting
**Tool**: `statamic.development.blade-lint`

Comprehensive linting with policy enforcement and optimization:
```json
{
  "tool": "statamic.development.blade-lint",
  "arguments": {
    "template": "@foreach($entries as $entry) {{ $entry->title }} @endforeach",
    "strict_mode": true,
    "auto_fix": true,
    "performance_analysis": true
  }
}
```

### Template Development Hints
**Tool**: `statamic.development.templates`

Now includes performance tips and edge case warnings:
```json
{
  "tool": "statamic.development.templates",
  "arguments": {
    "blueprint": "article",
    "context": "entry",
    "performance_analysis": true
  }
}
```

## Performance Analysis Features

### N+1 Query Detection

**Antlers Example - Problem:**
```antlers
{{ collection:blog }}
  <h2>{{ title }}</h2>
  {{ related_articles }}
    <p>{{ title }}</p>  <!-- N+1 query problem -->
  {{ /related_articles }}
{{ /collection:blog }}
```

**Detection Result:**
```json
{
  "issues": [
    {
      "type": "query_in_loop",
      "severity": "critical", 
      "message": "Collection query detected inside another collection loop",
      "suggestion": "Use relationships, eager loading, or cache the outer query",
      "line": 4
    }
  ],
  "metrics": {
    "complexity_score": 25,
    "memory_impact": "high"
  }
}
```

**Solution:**
```antlers
{{ collection:blog with="related_articles" }}
  <h2>{{ title }}</h2>
  {{ related_articles }}
    <p>{{ title }}</p>  <!-- Pre-loaded relationship -->
  {{ /related_articles }}
{{ /collection:blog }}
```

### Nested Loop Analysis

**Problem Detection:**
```antlers
{{ collection:categories }}
  {{ collection:products category:is="handle" }}
    {{ collection:reviews product:is="id" }}
      <p>{{ content }}</p>  <!-- Deeply nested loops -->
    {{ /collection:reviews }}
  {{ /collection:products }}
{{ /collection:categories }}
```

**Analysis Result:**
```json
{
  "issues": [
    {
      "type": "nested_loops",
      "severity": "high",
      "message": "Deeply nested loops detected in 'categories' tag",
      "suggestion": "Consider using query builder with eager loading or caching",
      "line": 3
    }
  ],
  "metrics": {
    "complexity_score": 30,
    "estimated_render_time": 500
  }
}
```

### Missing Pagination Detection

**Problem:**
```antlers
{{ collection:blog limit="500" }}
  <article>{{ title }}</article>
{{ /collection:blog }}
```

**Detection:**
```json
{
  "issues": [
    {
      "type": "missing_pagination",
      "severity": "high",
      "message": "Large collection limit (500) without pagination",
      "suggestion": "Add pagination for better performance"
    }
  ]
}
```

**Solution:**
```antlers
{{ collection:blog limit="20" paginate="true" }}
  <article>{{ title }}</article>
{{ /collection:blog }}

{{ paginate }}
  <nav>{{ links }}</nav>
{{ /paginate }}
```

### Excessive Partials Warning

**Problem:**
```antlers
{{ partial:header }}
{{ partial:nav }}
{{ partial:sidebar }}
{{ partial:breadcrumbs }}
{{ partial:content }}
{{ partial:related }}
{{ partial:comments }}
{{ partial:footer }}
{{ partial:scripts }}
{{ partial:analytics }}
{{ partial:social }}
{{ partial:newsletter }}
```

**Detection:**
```json
{
  "issues": [
    {
      "type": "excessive_partials",
      "severity": "medium",
      "message": "Found 12 partial includes",
      "suggestion": "Consider combining related partials or using cached sections"
    }
  ]
}
```

## Edge Case Detection

### Recursive Partial Prevention

**Problem Detection:**
```antlers
{{# In partial: navigation.antlers.html #}}
{{ partial:navigation }}  <!-- Recursive self-reference -->
```

**Analysis:**
```json
{
  "edge_cases": [
    {
      "type": "potential_recursion",
      "message": "Check partial for recursive includes",
      "severity": "warning"
    }
  ]
}
```

### Memory-Intensive Operations

**Problem:**
```antlers
{{ collection:products limit="10000" }}
  {{ assets }}
    {{ glide:url width="2000" height="2000" }}
  {{ /assets }}
{{ /collection:products }}
```

**Detection:**
```json
{
  "edge_cases": [
    {
      "type": "memory_intensive",
      "message": "Very large limit (10000) may cause memory issues",
      "severity": "critical"
    }
  ]
}
```

### XSS Vulnerability Detection

**Antlers - Problem:**
```antlers
{{{ user_input }}}  <!-- Unescaped HTML output -->
```

**Blade - Problem:**
```blade
{!! $user_content !!}  <!-- Unescaped output -->
```

**Detection:**
```json
{
  "edge_cases": [
    {
      "type": "xss_risk", 
      "message": "Triple braces output unescaped HTML - ensure data is sanitized",
      "severity": "high"
    }
  ]
}
```

**Solution:**
```antlers
{{ user_input | strip_tags }}  <!-- Escaped output -->
```

### Infinite Loop Risk Detection

**Problem:**
```antlers
{{ while condition }}
  {{ !-- No clear exit condition --}}
{{ /while }}
```

**Detection:**
```json
{
  "edge_cases": [
    {
      "type": "infinite_loop_risk",
      "message": "While loops can cause infinite loops if not properly bounded",
      "severity": "critical"
    }
  ]
}
```

## Optimization Recommendations

### Severity-Based Prioritization

**Critical Issues (Fix Immediately):**
- N+1 query problems
- Infinite loop risks  
- Memory-intensive operations
- XSS vulnerabilities

**High Priority:**
- Missing pagination for large datasets
- Deeply nested loops
- Unescaped output without sanitization

**Medium Priority:**
- Excessive partial includes
- Complex conditional logic
- Repeated markup patterns

**Low Priority:**
- Hardcoded URLs
- Missing alt text
- Non-descriptive link text

### Performance Metrics

The analyzer provides quantitative metrics:

```json
{
  "metrics": {
    "complexity_score": 45,
    "estimated_render_time": 250,
    "memory_impact": "medium"
  },
  "optimizations": {
    "immediate": {
      "action": "Fix critical performance issues",
      "impact": "Can improve performance by 50-80%",
      "priority": "urgent"
    },
    "caching": {
      "action": "Implement fragment caching", 
      "impact": "Can reduce render time by 30-50%",
      "priority": "high"
    }
  }
}
```

## Integration with Development Tools

### Template Hints with Performance Analysis

The `statamic.development.templates` tool now includes performance guidance:

```json
{
  "performance_tips": {
    "collections": {
      "tip": "Use limit and sort parameters for better performance",
      "example": "{{ collection:blog limit=\"10\" sort=\"date:desc\" }}",
      "avoid": "Avoid deeply nested collection loops"
    },
    "assets": {
      "tip": "Always specify dimensions for Glide images",
      "example": "{{ glide:image width=\"300\" height=\"200\" quality=\"80\" }}",
      "avoid": "Avoid processing large images without constraints"
    }
  },
  "edge_case_warnings": {
    "memory_usage": {
      "warning": "Large collections can cause memory issues",
      "solution": "Use pagination and limit parameters",
      "example": "{{ collection:blog limit=\"50\" paginate=\"10\" }}"
    },
    "recursive_partials": {
      "warning": "Recursive partial includes can cause infinite loops",
      "solution": "Implement depth limits and cycle detection",
      "example": "{{ partial:navigation depth=\"3\" }}"
    }
  }
}
```

## Best Practices Summary

### Antlers Optimization
1. **Use relationships**: Pre-load related content to avoid N+1 queries
2. **Implement pagination**: Never load more than 50-100 items without pagination
3. **Cache expensive operations**: Use fragment caching for complex template logic
4. **Escape output**: Use `{{ }}` instead of `{{{ }}}` unless specifically needed
5. **Limit partial depth**: Avoid more than 5 levels of nested partials

### Blade Optimization
1. **Move logic to controllers**: Avoid `@php` blocks in templates
2. **Use Statamic components**: Prefer `<x-statamic:*>` over facades
3. **Cache view composers**: Pre-compute expensive data operations
4. **Validate input**: Always escape user content with `{{ }}` not `{!! !!}`
5. **Create reusable components**: Extract repeated markup into Blade components

### Security Guidelines
1. **Sanitize user input**: Never output unescaped user-generated content
2. **Validate permissions**: Check user access before displaying sensitive data
3. **Escape HTML attributes**: Use proper escaping in attribute values
4. **Limit file operations**: Avoid file system operations in templates
5. **Monitor form inputs**: Validate and sanitize all form submissions

The OptimizedTemplateAnalyzer ensures your templates are production-ready, secure, and performant while following Statamic best practices.