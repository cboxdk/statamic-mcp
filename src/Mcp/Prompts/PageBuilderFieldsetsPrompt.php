<?php

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Arguments;
use Laravel\Mcp\Server\Prompts\PromptResult;

class PageBuilderFieldsetsPrompt extends Prompt
{
    protected string $name = 'page-builder-fieldsets-best-practices';

    protected string $description = 'Comprehensive guide for building optimal page builder fieldsets in Statamic with replicators, component architecture, and user experience best practices';

    public function arguments(): Arguments
    {
        return new Arguments;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): PromptResult
    {
        $content = <<<'PROMPT'
# Page Builder Fieldsets Best Practices for Statamic

You are an expert Statamic developer specializing in creating sophisticated page builder fieldsets using replicators. Your expertise covers component architecture, user experience, performance optimization, and maintainable content structures.

## Core Principles

### 1. Component-Based Architecture
- **Modular Design**: Each replicator set represents a distinct, self-contained component
- **Single Responsibility**: Components should have one clear purpose (hero section, testimonial, feature grid, etc.)
- **Reusability**: Design components that can be used across different contexts and templates
- **Composability**: Components should work well together without conflicts

### 2. Replicator Field Structure
```yaml
page_builder:
  type: replicator
  display: Page Builder
  instructions: Build your page using modular components
  collapse: false
  fullscreen: true
  sets:
    hero:
      display: Hero Section
      fields:
        heading: 
          type: text
          display: Heading
          width: 66
        subheading:
          type: text
          display: Subheading
          width: 33
        content:
          type: markdown
          display: Content
        background_image:
          type: assets
          display: Background Image
          max_files: 1
```

### 3. Essential Component Types

#### Content Components
- **Text Block**: Rich content with Bard or Markdown
- **Quote/Testimonial**: Customer feedback with author details
- **FAQ Section**: Expandable question/answer pairs

#### Media Components
- **Image Gallery**: Grid, masonry, or slider layouts
- **Video Embed**: YouTube, Vimeo, or direct video files
- **Image + Text**: Side-by-side content combinations

#### Interactive Components
- **Call to Action**: Buttons with tracking and styling options
- **Contact Form**: Embedded or linked form references
- **Newsletter Signup**: Email capture with styling

#### Layout Components
- **Feature Grid**: Icon + title + description patterns
- **Stats/Numbers**: Highlighted metrics with animations
- **Card Layouts**: Product/service/team member cards

### 4. Field Configuration Best Practices

#### Use Appropriate Field Types
```yaml
# For rich content
content:
  type: bard
  display: Content
  buttons: [bold, italic, unorderedlist, link]
  save_html: false

# For simple text with character limits
heading:
  type: text
  display: Heading
  instructions: Keep under 60 characters for best display
  character_limit: 60

# For selections with clear options
layout_type:
  type: select
  display: Layout Type
  options:
    grid: Grid Layout
    masonry: Masonry Layout
    slider: Image Slider
  default: grid
```

#### Width Management
```yaml
# Use width for logical field grouping
title:
  width: 66
subtitle:
  width: 33
# This creates intuitive 2/3 + 1/3 layout
```

#### Instructions and Help Text
```yaml
cta_button:
  type: text
  display: Button Text
  instructions: Short, action-oriented text (e.g., "Get Started", "Learn More")
```

### 5. Advanced Component Patterns

#### Nested Replicators for Complex Components
```yaml
feature_grid:
  display: Feature Grid
  fields:
    section_heading:
      type: text
      display: Section Heading
    features:
      type: replicator
      display: Features
      collapse: true
      sets:
        feature:
          display: Feature Item
          fields:
            icon:
              type: text
              display: Icon Class
              instructions: CSS class for icon (e.g., 'fas fa-rocket')
            title:
              type: text
              display: Feature Title
            description:
              type: textarea
              display: Feature Description
```

#### Conditional Fields
```yaml
background_type:
  type: select
  display: Background Type
  options:
    color: Solid Color
    image: Background Image
    video: Background Video

background_color:
  type: color
  display: Background Color
  if:
    background_type: color

background_image:
  type: assets
  display: Background Image
  max_files: 1
  if:
    background_type: image
```

### 6. Layout and Styling Systems

#### Consistent Layout Options
```yaml
layout:
  type: select
  display: Component Width
  options:
    full: Full Width
    contained: Contained (max-width)
    narrow: Narrow (reading width)
  default: contained

alignment:
  type: select
  display: Text Alignment
  options:
    left: Left Aligned
    center: Center Aligned
    right: Right Aligned
  default: left
```

#### Spacing Controls
```yaml
spacing:
  type: select
  display: Section Spacing
  instructions: Vertical spacing around this component
  options:
    small: Small (2rem)
    medium: Medium (4rem)
    large: Large (6rem)
    none: No spacing
  default: medium
```

#### Design System Integration
```yaml
color_scheme:
  type: select
  display: Color Scheme
  options:
    default: Default
    primary: Primary Brand
    secondary: Secondary Brand
    dark: Dark Theme
    light: Light Theme
```

### 7. User Experience Optimization

#### Component Previews
- Use clear, descriptive display names
- Add helpful instructions for complex fields
- Provide sensible defaults to reduce decision fatigue
- Use collapse: true for components with many fields

#### Field Organization
```yaml
# Group related fields with sections
content_section:
  type: section
  display: Content
  instructions: Main content for this component

styling_section:
  type: section
  display: Styling
  instructions: Visual appearance options
```

#### Validation and Constraints
```yaml
image:
  type: assets
  display: Feature Image
  max_files: 1
  mime_types:
    - image/jpeg
    - image/png
    - image/webp
  instructions: Recommended size: 1200x800px

email:
  type: text
  display: Contact Email
  input_type: email
  validate: required|email
```

### 8. Performance Considerations

#### Image Optimization
```yaml
hero_image:
  type: assets
  display: Hero Image
  max_files: 1
  instructions: |
    Upload high-quality images. The system will automatically generate:
    - WebP versions for modern browsers
    - Multiple sizes for responsive images
    - Optimized versions for performance
```

#### Lazy Loading Support
```yaml
enable_lazy_loading:
  type: toggle
  display: Enable Lazy Loading
  instructions: Improves page load speed by loading images as needed
  default: true
```

### 9. SEO and Accessibility

#### Alt Text and Descriptions
```yaml
image:
  type: assets
  display: Image
  max_files: 1

image_alt:
  type: text
  display: Image Alt Text
  instructions: Describe the image for screen readers and SEO
  if:
    image: not_empty
```

#### Structured Data Support
```yaml
enable_schema:
  type: toggle
  display: Enable Schema Markup
  instructions: Add structured data for better search engine understanding
  default: false
```

### 10. Maintenance and Scalability

#### Version Control
- Use import statements to share common field definitions
- Create base fieldsets for common patterns
- Version your component schemas
- Document breaking changes

#### Component Library
```yaml
# Import common components
import: page_builder_base

# Extend with specific components
additional_sets:
  custom_component:
    display: Custom Component
    fields:
      # Custom fields here
```

#### Naming Conventions
- Use snake_case for field handles: `hero_section`, `call_to_action`
- Use clear, descriptive display names: "Hero Section", "Call to Action"
- Prefix related fields: `seo_title`, `seo_description`, `seo_image`

### 11. Testing and Quality Assurance

#### Content Testing
- Test with realistic content lengths
- Verify responsive behavior
- Check accessibility with screen readers
- Validate structured data output

#### Performance Testing
- Monitor page load times with heavy image usage
- Test lazy loading effectiveness
- Validate image optimization pipeline
- Check component render performance

## Implementation Workflow

1. **Planning Phase**
   - Identify required components based on design mockups
   - Group related functionality into logical components
   - Define the styling system and layout options

2. **Development Phase**
   - Start with basic components and build complexity gradually
   - Use the FieldsetsStructureTool to generate initial structures
   - Test each component individually before combining

3. **Optimization Phase**
   - Use the analyze and optimize actions to identify improvements
   - Refine field organization based on user feedback
   - Implement performance optimizations

4. **Documentation Phase**
   - Document component usage and best practices
   - Create content guidelines for editors
   - Maintain a component library reference

## Common Pitfalls to Avoid

- **Over-complexity**: Don't create too many similar components
- **Poor field organization**: Group related fields logically
- **Missing instructions**: Always provide clear guidance for editors
- **Inconsistent naming**: Use consistent conventions throughout
- **Performance neglect**: Consider the impact of heavy image usage
- **Accessibility oversight**: Include alt text and semantic structure
- **Mobile ignorance**: Test components on various screen sizes

## Advanced Techniques

### Dynamic Component Loading
```yaml
component_type:
  type: select
  display: Component Type
  options:
    static: Static Content
    dynamic: Dynamic Content (from entries)

related_entries:
  type: entries
  display: Related Content
  collections: [articles, products]
  if:
    component_type: dynamic
```

### Multi-language Support
```yaml
content:
  type: text
  display: Content
  localizable: true

image:
  type: assets
  display: Image
  localizable: false  # Images often shared across languages
```

### Custom Field Types Integration
```yaml
# If using custom field types
advanced_slider:
  type: slider_pro  # Custom field type
  display: Image Slider
  config:
    autoplay: true
    transition_speed: 500
```

Remember: The best page builder fieldset is one that empowers content creators while maintaining design consistency and technical performance. Always prioritize user experience over feature complexity.
PROMPT;

        return new PromptResult(
            content: $content,
            description: $this->description
        );
    }
}
