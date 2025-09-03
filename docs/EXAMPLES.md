# AI Conversation Examples

Real-world examples of how to use the Statamic MCP Server with AI assistants.

## System Analysis Conversations

### "What version of Statamic is installed?"

**AI Response:**
> I'll check your Statamic installation information.

*Uses `statamic.system.info` tool*

> Your Statamic installation:
> - **Version**: 5.32.0
> - **Edition**: Pro (licensed)
> - **Storage**: Mixed (file-based + Runway database)
> - **Cache**: Redis (working)
> - **Multi-site**: Yes (3 sites configured)
> - **Features**: API enabled, Live Preview enabled, Static caching active

### "What addons do I have installed?"

**AI Response:**
> Let me scan your installed Statamic addons.

*Uses `statamic.addons.scan` with all options enabled*

> **Installed Addons:**
> 1. **SEO Pro** (statamic/seo-pro v6.2.1)
>    - Tags: `seo`, `seo:title`, `seo:description`
>    - Modifiers: `seo_title`, `og_title`
>    - Documentation: https://statamic.dev/seo-pro
> 
> 2. **Simple Commerce** (doublethreedigital/simple-commerce v6.1.8)
>    - Tags: `sc:cart`, `sc:products`, `sc:checkout`
>    - Field types: `money`, `product_variants`
>    - Documentation: https://simple-commerce.duncanmcclean.com
>
> You also have access to the Statamic marketplace with 200+ community addons.

## Blueprint Analysis Conversations

### "Show me my blueprint structures"

**AI Response:**
> I'll analyze all your Statamic blueprints.

*Uses `statamic.blueprints.scan` with relationships and validation*

> **Found 5 Blueprints:**
> 
> **Article Blueprint:**
> - `title` (text, required)
> - `slug` (slug, from title)
> - `content` (bard with image and quote sets)
> - `author` (users relationship)
> - `categories` (taxonomy:categories, multiple)
> - `featured_image` (assets, max 1)
> - `published_at` (date)
> 
> **Product Blueprint:**
> - `name` (text, required)
> - `price` (money, required) 
> - `variants` (replicator: size, color, stock)
> - `gallery` (assets, multiple)
> - `description` (markdown)
> 
> **Relationships Found:**
> - Articles → Users (author)
> - Articles → Categories taxonomy
> - Products → Asset containers

### "Generate TypeScript types for my Article blueprint"

**AI Response:**
> I'll generate TypeScript interfaces for your Article blueprint.

*Uses `statamic.blueprints.types` with TypeScript format*

> ```typescript
> export interface Article {
>   id: string;
>   title: string;
>   slug: string;
>   content: BardContent[];
>   author?: User;
>   categories: Category[];
>   featured_image?: Asset;
>   published_at?: string;
>   created_at: string;
>   updated_at: string;
> }
> 
> export interface BardContent {
>   type: 'paragraph' | 'heading' | 'image' | 'quote';
>   attrs?: Record<string, any>;
>   content?: BardContent[];
> }
> 
> export interface User {
>   id: string;
>   name: string;
>   email: string;
> }
> 
> export interface Category {
>   id: string;
>   title: string;
>   slug: string;
> }
> ```

## Documentation Search Conversations

### "How do I configure a Bard field?"

**AI Response:**
> Let me search for Bard field configuration documentation.

*Uses `statamic.docs.search` for "bard field configuration"*

> **Bard Field Configuration:**
> 
> The Bard field is a rich content editor that supports:
> 
> **Basic Configuration:**
> ```yaml
> handle: content
> field:
>   type: bard
>   display: Content
>   toolbar_buttons:
>     - h2
>     - h3
>     - bold
>     - italic
>     - unorderedlist
>     - orderedlist
>     - removeformat
>     - quote
>     - anchor
>     - image
>     - table
> ```
> 
> **Custom Sets:**
> ```yaml
> field:
>   type: bard
>   sets:
>     image:
>       display: Image
>       fields:
>         - handle: image
>           field: 
>             type: assets
>             container: main
>         - handle: caption
>           field:
>             type: text
>     quote:
>       display: Quote
>       fields:
>         - handle: text
>           field:
>             type: textarea
>         - handle: author
>           field:
>             type: text
> ```
> 
> **Advanced Options:**
> - `save_html: true` - Save as HTML instead of ProseMirror JSON
> - `toolbar_buttons` - Customize available buttons
> - `link_noopener: true` - Add security attributes to links
> - `target_blank: true` - Open links in new tab
> 
> Full documentation: https://statamic.dev/fieldtypes/bard

### "Find form tag documentation"

**AI Response:**
> I'll search for Statamic form tag documentation.

*Uses `statamic.docs.search` for "form tag" in tags section*

> **Form Tags Documentation:**
> 
> **Basic Form Creation:**
> ```blade
> <s:form:create handle="contact">
>   <div class="field">
>     <label for="name">Name</label>
>     <input type="text" name="name" value="{{ old:name }}" required>
>     {{ error:name }}
>   </div>
>   
>   <div class="field">
>     <label for="email">Email</label>
>     <input type="email" name="email" value="{{ old:email }}" required>
>     {{ error:email }}
>   </div>
>   
>   <div class="field">
>     <label for="message">Message</label>
>     <textarea name="message" required>{{ old:message }}</textarea>
>     {{ error:message }}
>   </div>
>   
>   <button type="submit">Send Message</button>
> </s:form:create>
> ```
> 
> **Available Form Tags:**
> - `<s:form:create>` - Create form with validation
> - `<s:form:errors>` - Display validation errors
> - `<s:form:success>` - Show success message
> - `{{ old:field_name }}` - Repopulate field values
> - `{{ error:field_name }}` - Show field-specific errors
> 
> **Form Configuration:**
> Forms are configured in `resources/forms/` with blueprints and email settings.

## Template Development Conversations

### "Help me create an Antlers template for blog entries"

**AI Response:**
> I'll help you create an Antlers template for displaying blog entries. First, let me get some context about your blueprints.

*Uses `statamic.blueprints.scan` and `statamic.antlers.hints` for blog/article context*

> Based on your Article blueprint, here's a comprehensive blog template:
> 
> ```antlers
> {{# Blog listing page #}}
> <div class="blog-container">
>   <h1>Latest Articles</h1>
>   
>   {{ collection:articles sort="published_at:desc" limit="10" }}
>     <article class="blog-post">
>       <header>
>         <h2><a href="{{ url }}">{{ title }}</a></h2>
>         <div class="meta">
>           {{ if author }}
>             by {{ author:name }}
>           {{ /if }}
>           {{ if published_at }}
>             on {{ published_at format="F j, Y" }}
>           {{ /if }}
>         </div>
>       </header>
>       
>       {{ if featured_image }}
>         <img src="{{ featured_image:url }}" 
>              alt="{{ featured_image:alt ? featured_image:alt : title }}"
>              class="featured-image">
>       {{ /if }}
>       
>       <div class="content">
>         {{ content | truncate:200 }}
>       </div>
>       
>       {{ if categories }}
>         <div class="categories">
>           {{ categories }}
>             <span class="category">{{ title }}</span>
>           {{ /categories }}
>         </div>
>       {{ /if }}
>       
>       <a href="{{ url }}" class="read-more">Read More</a>
>     </article>
>   {{ /collection:articles }}
> </div>
> ```
> 
> **Available Variables:**
> Based on your Article blueprint, you have access to:
> - `title`, `slug`, `content` (Bard field)
> - `author` (User relationship) 
> - `categories` (Taxonomy)
> - `featured_image` (Asset)
> - `published_at` (Date)

### "Validate this Antlers template"

**User provides:**
```antlers
{{ title }}
{{ author.name }}
{{ content }}
{{ category:title }}
```

**AI Response:**
> Let me validate your Antlers template against your blueprints.

*Uses `statamic.antlers.validate` with the provided template*

> **Validation Results:**
> 
> ✅ **Valid:**
> - `{{ title }}` - Available in Article blueprint
> - `{{ content }}` - Available as Bard field
> 
> ⚠️ **Issues Found:**
> 1. `{{ author.name }}` should be `{{ author:name }}`
>    - Use colon syntax for relationship fields
> 
> 2. `{{ category:title }}` should be `{{ categories }}{{ title }}{{ /categories }}`
>    - Field name is `categories` (plural)
>    - Multiple categories require loop syntax
> 
> **Corrected Template:**
> ```antlers
> {{ title }}
> {{ if author }}{{ author:name }}{{ /if }}
> {{ content }}
> {{ categories }}
>   {{ title }}
> {{ /categories }}
> ```

### "Lint this Blade template"

**User provides:**
```blade
@php 
$entries = Entry::all();
$categories = \Statamic\Facades\Taxonomy::find('categories');
@endphp

<div>
  @foreach($entries as $entry)
    <h2>{{ $entry->title }}</h2>
    <img src="{{ $entry->featured_image }}">
  @endforeach
</div>
```

**AI Response:**
> I'll lint your Blade template for Statamic best practices.

*Uses `statamic.blade.lint` with auto-fix suggestions*

> **❌ Issues Found:**
> 
> 1. **Direct Facade Usage** (Line 2-3)
>    - Avoid `Entry::all()` and `\Statamic\Facades\Taxonomy::find()` in views
>    - Use Statamic Blade tags instead
> 
> 2. **Missing Alt Text** (Line 8)
>    - Images must have alt attributes for accessibility
> 
> 3. **Inline PHP** (Lines 1-4)
>    - Move data logic to controller or use Statamic tags
> 
> **✅ Auto-fix Suggestions:**
> ```blade
> <div>
>   <s:collection from="entries">
>     <h2>{{ title }}</h2>
>     <img src="{{ featured_image:url }}" 
>          alt="{{ featured_image:alt ? featured_image:alt : title }}">
>   </s:collection>
> </div>
> ```
> 
> **Improvements:**
> - ✅ Uses `<s:collection>` tag instead of facade
> - ✅ Proper alt text with fallback
> - ✅ No inline PHP required
> - ✅ Follows Statamic conventions

## Field Type Exploration Conversations

### "What's the difference between Replicator and Grid fields?"

**AI Response:**
> I'll explain the differences between Replicator and Grid field types.

*Uses `statamic.fieldtypes.list` for both field types with examples*

> **Replicator vs Grid Field Types:**
> 
> **Replicator Field:**
> - **Purpose**: Flexible, repeatable content blocks with different types
> - **Structure**: Each item can be a different "set" with unique fields
> - **Best for**: Mixed content like article sections, landing page builders
> 
> ```yaml
> replicator_field:
>   type: replicator
>   sets:
>     text_block:
>       display: Text Block
>       fields:
>         - handle: heading
>           field: { type: text }
>         - handle: content
>           field: { type: markdown }
>     image_block:
>       display: Image
>       fields:
>         - handle: image
>           field: { type: assets }
>         - handle: caption
>           field: { type: text }
> ```
> 
> **Grid Field:**
> - **Purpose**: Uniform, tabular data with consistent columns
> - **Structure**: Every row has the same field structure
> - **Best for**: Tables, pricing tiers, team members
> 
> ```yaml
> grid_field:
>   type: grid
>   fields:
>     - handle: name
>       field: { type: text }
>     - handle: position
>       field: { type: text }
>     - handle: email
>       field: { type: email }
>     - handle: photo
>       field: { type: assets, max_files: 1 }
> ```
> 
> **When to Use:**
> - **Replicator**: Variable content types (like page builders)
> - **Grid**: Consistent data structures (like data tables)

### "Show me all relationship field types"

**AI Response:**
> I'll list all field types that handle relationships.

*Uses `statamic.fieldtypes.list` with category "relationships"*

> **Relationship Field Types:**
> 
> **1. Entries**
> - Links to other entries
> - Can filter by collection
> - Supports multiple selections
> 
> ```yaml
> related_articles:
>   type: entries
>   collections: [blog, news]
>   max_items: 3
> ```
> 
> **2. Taxonomy**
> - Links to taxonomy terms
> - Can specify which taxonomies
> - Supports creation of new terms
> 
> ```yaml
> categories:
>   type: taxonomy
>   taxonomies: [categories, tags]
>   create: true
> ```
> 
> **3. Users**
> - Links to user accounts
> - Can filter by role/group
> - Supports multiple selections
> 
> ```yaml
> authors:
>   type: users
>   roles: [author, editor]
>   max_items: 2
> ```
> 
> **4. Collections**
> - References entire collections
> - Used for navigation and organization
> 
> ```yaml
> featured_collection:
>   type: collections
>   max_items: 1
> ```
> 
> **5. Assets**
> - Links to files/images
> - Can specify container
> - Supports upload and organization
> 
> ```yaml
> gallery:
>   type: assets
>   container: images
>   folder: gallery
>   max_files: 10
> ```

## Advanced Workflow Conversations

### "Set up a complete product catalog system"

**AI Response:**
> I'll help you design a complete product catalog system. Let me first analyze your current setup and then provide recommendations.

*Uses multiple tools: `statamic.system.info`, `statamic.blueprints.scan`, `statamic.addons.scan`*

> **Current Setup Analysis:**
> - Statamic Pro (multi-site capable)
> - No e-commerce addon detected
> - File-based storage (suitable for products)
> - Redis cache (good for performance)
> 
> **Recommended Architecture:**
> 
> **1. Install E-commerce Addon:**
> ```bash
> composer require doublethreedigital/simple-commerce
> ```
> 
> **2. Product Blueprint:**
> ```yaml
> title: Product
> fields:
>   - handle: title
>     field: { type: text, required: true }
>   - handle: slug
>     field: { type: slug, from: title }
>   - handle: price
>     field: { type: money, required: true }
>   - handle: sale_price
>     field: { type: money }
>   - handle: description
>     field: { type: markdown }
>   - handle: gallery
>     field: { type: assets, container: products, max_files: 10 }
>   - handle: variants
>     field:
>       type: replicator
>       sets:
>         variant:
>           fields:
>             - handle: name
>               field: { type: text }
>             - handle: price
>               field: { type: money }
>             - handle: sku
>               field: { type: text }
>             - handle: stock
>               field: { type: integer }
>   - handle: categories
>     field: { type: taxonomy, taxonomies: [product_categories] }
>   - handle: featured
>     field: { type: toggle }
>   - handle: in_stock
>     field: { type: toggle, default: true }
> ```
> 
> **3. Category Taxonomy:**
> ```yaml
> title: Product Categories
> handle: product_categories
> ```
> 
> **4. Product Listing Template:**
> ```blade
> <div class="product-grid">
>   <s:collection from="products" sort="featured:desc|title:asc">
>     <div class="product-card">
>       <a href="{{ url }}">
>         @if($gallery)
>           <img src="{{ gallery.0:url }}" alt="{{ gallery.0:alt ?? title }}">
>         @endif
>         <h3>{{ title }}</h3>
>         <p class="price">
>           @if($sale_price)
>             <span class="sale">${{ sale_price }}</span>
>             <span class="original">${{ price }}</span>
>           @else
>             ${{ price }}
>           @endif
>         </p>
>       </a>
>     </div>
>   </s:collection>
> </div>
> ```
> 
> **Next Steps:**
> 1. Create the product blueprint
> 2. Set up product categories taxonomy  
> 3. Create product listing and detail templates
> 4. Configure Simple Commerce for cart/checkout
> 5. Add search and filtering functionality

This comprehensive example shows how the MCP server can provide end-to-end development assistance, from system analysis to complete feature implementation.