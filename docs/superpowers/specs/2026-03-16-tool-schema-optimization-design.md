# MCP Tool Schema Optimization — Design Spec

## Problem

LLMs consistently send malformed payloads to our MCP tools because:
1. Parameter descriptions are too vague (36 params with <10 words)
2. No inline examples or format guidance
3. Complex params (`data`, `fields`, `filters`) are opaque `object()` types with no structure
4. Action-specific required params are undocumented — LLMs guess which params each action needs
5. Custom validation reimplements Statamic internals poorly, producing silent corruption (broken blueprints that crash the site)
6. Auto-correction hides errors instead of teaching LLMs the correct format
7. `type` parameter name collides with JSON Schema keyword
8. Duplicate parameters (`global_set`/`handle`, `container`/`handle`) cause confusion

## Solution

Five changes applied to all 9 router tools:

1. **Action descriptions with required params** — each `action` enum description lists required and optional params per action
2. **Expanded parameter descriptions** — discovery-first strategy for complex params, format+example for simple params
3. **Strict validation with instructive errors** — no auto-correction, validate via Statamic APIs, return precise error messages showing the correct format
4. **Delegate to Statamic APIs** — use Blueprint::setContents(), FieldtypeRepository, Entry::createRules(), Fields::process() instead of custom implementations
5. **`type` → `resource_type` rename** + duplicate parameter cleanup

## 1. Description Strategy

### Three categories

**Simple params** (handle, id, collection, limit) — what it is + format + example:
```php
->description('Blueprint identifier in snake_case. Example: "blog_post", "product"')
->description('Entry UUID. Required for get, update, delete, publish, unpublish actions')
->description('Maximum results to return (default: 100, max: 500)')
```

**Enum params** (action) — per-action required params:
```php
->description(
    'Action to perform. Required params per action: '
    . 'list (collection; optional: limit, offset, include_unpublished), '
    . 'get (collection, id), '
    . 'create (collection, data — use statamic-blueprints get to see field structure first), '
    . 'update (collection, id, data), '
    . 'delete (collection, id), '
    . 'publish (collection, id), '
    . 'unpublish (collection, id)'
)
```

**Complex params** (data, fields, filters) — discovery-first instruction, no format examples:
```php
->description(
    'Entry field values. Structure must match the collection blueprint including nested '
    . 'types (bard, replicator, grid). Use statamic-blueprints action "get" with the '
    . 'collection handle to see required fields, types, and nesting before sending data.'
)
```

### No inline format examples for complex params

`data` values depend entirely on the blueprint. Bard fields are arrays of block objects, replicator fields are arrays of set objects, grid fields are arrays of row objects. We cannot document all permutations. The LLM MUST call `statamic-blueprints get` first.

## 2. `type` → `resource_type` Rename

BaseRouter's `type` parameter renamed to `resource_type` everywhere:
- `BaseRouter::defineSchema()` — parameter name change
- All 9 router `defineSchema()` overrides
- All `executeAction()` methods that read `$arguments['type']`
- All tests that reference `'type' =>`

## 3. Duplicate Parameter Cleanup

### GlobalsRouter
Remove `global_set` parameter. Keep `handle` only (consistent with all other routers).
Update all internal references from `$arguments['global_set']` to `$arguments['handle']`.

### AssetsRouter
Remove duplicate `handle` parameter (line 59). Keep `container` for container handle (more descriptive).
Update internal references.

## 4. Statamic API Delegation

### Blueprint operations

**Remove**: `normalizeFields()`, `validateFieldDefinitions()`, `sanitizeFieldConfig()`, `autoCorrectFieldConfig()`, `ALLOWED_FIELD_TYPES` constant, `ALLOWED_FIELD_CONFIG_KEYS` constant.

**Replace with**:

```php
// Fieldtype validation
$fieldtypeRepo = app(\Statamic\Fields\FieldtypeRepository::class);
try {
    $fieldtypeRepo->find($type);
} catch (\Statamic\Fields\FieldtypeNotFoundException $e) {
    return $this->createErrorResponse(
        "Unknown field type \"{$type}\". Available types: "
        . $fieldtypeRepo->handles()->implode(', ')
    )->toArray();
}

// Step 1: Validate all fieldtypes BEFORE saving (setContents does NOT validate types)
foreach ($fields as $field) {
    $fieldtypeRepo->find($field['field']['type']); // Throws if invalid
}

// Step 2: Validate field structure (each field must have 'handle' + 'field' with 'type')
// Return instructive error if flat format detected

// Step 3: Blueprint creation via setContents (auto-normalizes tab/section structure only)
$blueprint = Blueprint::make($handle)->setNamespace($namespace);
$blueprint->setContents($contents);
$blueprint->save();
```

### Entry/Term create and update

**Remove**: Direct `$entry->set()` calls without validation.

**Replace with** Statamic's `FieldsValidator` (already partially used in EntriesRouter — extend to all write operations):

```php
use Statamic\Fields\Validator as FieldsValidator;

// 1. Get blueprint
$blueprint = $collection->entryBlueprint();

// 2. Validate via Statamic's FieldsValidator
$fields = $blueprint->fields()->addValues($data);
$fieldsValidator = (new FieldsValidator)
    ->fields($fields)
    ->withContext(['collection' => $collection->handle()]);

try {
    $validatedData = $fieldsValidator->validate();
} catch (\Illuminate\Validation\ValidationException $e) {
    $errors = collect($e->errors())
        ->map(fn($msgs, $key) => "$key: " . implode(', ', $msgs))
        ->implode('; ');
    return $this->createErrorResponse("Validation failed. {$errors}")->toArray();
}

// 3. Save with validated data
$entry->data($validatedData);
$entry->save();
```

`FieldsValidator::validate()` internally handles pre-processing, rule generation, and validation. Bard, Replicator, Grid, Terms, Assets — all handled correctly by their respective fieldtype validators.

**Note**: EntriesRouter already uses `FieldsValidator` partially. The change is to ensure ALL write paths (entries, terms, globals) use this pattern consistently, and that validation errors are returned as instructive MCP error responses.

### Global set updates

Same `FieldsValidator` pattern — validate against `$globalSet->blueprint()->fields()`.

### Term create and update

Same pattern — validate against `$taxonomy->termBlueprint()->fields()`, process, then save.

## 5. Strict Validation with Instructive Errors

### Principle

Never auto-correct structural errors. Validate strictly, return error messages that show:
1. What was wrong
2. What the correct format is
3. A specific example

### Error patterns

**Missing required param:**
```
Error: The "create" action requires "collection" and "data" parameters.
You sent: {"action": "create", "data": {"title": "Hello"}}
Missing: "collection"
```

**Wrong field structure in blueprint:**
```
Error: Field "title" is missing the "field" key.
Correct format: {"handle": "title", "field": {"type": "text", "display": "Title"}}
You sent: {"handle": "title", "type": "text", "display": "Title"}
```

**Unknown fieldtype:**
```
Error: Unknown field type "richtext".
Available types: text, textarea, markdown, bard, replicator, grid, assets, ...
```

**Validation failure from Statamic:**
```
Error: Validation failed. title: The title field is required; slug: The slug has already been taken
```

**Wrong param name (near-miss):**
```
Error: Unknown parameter "taxonomy". Did you mean "taxonomies"?
The terms fieldtype uses "taxonomies" as an array of taxonomy handles.
```

Near-miss detection uses a hardcoded map of common LLM mistakes (not Levenshtein — too unpredictable):
```php
private const PARAM_CORRECTIONS = [
    'taxonomy' => 'taxonomies',
    'collection' => 'collections',  // for field config, not router param
    'field_type' => 'type',
    'fieldtype' => 'type',
    'name' => 'handle',             // common confusion
];
```

### Remove auto-correction

Delete these methods from BlueprintsRouter:
- `autoCorrectFieldConfig()`
- The silent flat→nested field wrapping in `validateFieldDefinitions()`

Replace with strict validation that returns clear errors.

## Per-Router Changes

### statamic-blueprints
- Action description with required params per action
- `fields` description: discovery-first instruction
- Remove `ALLOWED_FIELD_TYPES`, `ALLOWED_FIELD_CONFIG_KEYS` constants
- Remove `normalizeFields()`, `validateFieldDefinitions()`, `sanitizeFieldConfig()`, `autoCorrectFieldConfig()`
- Use `FieldtypeRepository::find()` for type validation
- Use `Blueprint::setContents()` for normalization
- Validate field structure strictly before saving

### statamic-entries
- Action description with required params
- `data` description: discovery-first, reference blueprint
- `id` description: expanded with action context
- `filters` description: explain available filter keys
- Validate data against blueprint via `Fields::validator()`
- Process data via `Fields::process()` before saving

### statamic-terms
- Action description with required params
- `data` description: discovery-first, reference taxonomy blueprint
- Validate against taxonomy blueprint
- Process via fieldtype pipeline

### statamic-globals
- Action description with required params
- Remove `global_set` param, keep `handle`
- `data` description: discovery-first
- Validate against global set blueprint
- Process via fieldtype pipeline

### statamic-structures
- `resource_type` rename (was `type`)
- Action description with required params
- `data` description expanded per resource_type
- `handle` description expanded

### statamic-assets
- `resource_type` rename
- Remove duplicate `handle` param, keep `container`
- Action description with required params
- `data` description expanded

### statamic-users
- `resource_type` rename
- Action description with required params
- `data` description: specify required fields per resource_type (user needs email, role needs handle+title, group needs handle)
- Expand `email`, `handle`, `role` descriptions

### statamic-system
- Action description with required params
- Expand `cache_type`, `config_key`, `config_value` descriptions

### statamic-content-facade
- Action description with required params
- `filters` description expanded

### BaseRouter
- Rename `type` → `resource_type` in schema
- Expand `resource_type` description

## Testing

- All existing tests updated for `resource_type` rename
- New tests for strict validation error messages
- Test that blueprint creation with flat fields returns instructive error (not silent corruption)
- Test that entry creation with invalid data returns Statamic validation errors
- Test that unknown fieldtypes return available types list
- Test near-miss parameter suggestions ("taxonomy" → "Did you mean taxonomies?")

## Breaking Changes & Migration

### `type` → `resource_type` rename

This is a breaking change for MCP clients sending `"type": "collection"` etc. Since this addon is pre-1.0 and the `type` parameter conflates with JSON Schema's `type` keyword causing real agent confusion, the rename is justified without a deprecation period.

**Scope**: BaseRouter defines `type` in `defineSchema()`. Only 4 routers re-declare it in their own `defineSchema()`: StructuresRouter, AssetsRouter, UsersRouter, SystemRouter. All 9 routers inherit the base schema, but only routers that read `$arguments['type']` in `executeAction()` need code changes (StructuresRouter, AssetsRouter, UsersRouter, SystemRouter — plus their Concerns traits).

### `global_set` parameter removal (GlobalsRouter)

Accept both `handle` and `global_set` with `global_set` deprecated. Log a deprecation notice in the response when `global_set` is used. Remove in next major version.

### `handle` parameter removal (AssetsRouter)

Same approach — accept both `container` and `handle` for container operations, deprecate `handle`.

### Auto-correction removal

Behavioral breaking change: tools that previously silently accepted malformed input will now return error responses. This is intentional — silent corruption was worse.

## Scope Exclusions

- `statamic-discovery` and `statamic-schema` (education tools) are out of scope — they already have good descriptions
- Prompt/system message optimization is out of scope — this spec focuses on tool schemas only

## What Gets Deleted

- `BlueprintsRouter::ALLOWED_FIELD_TYPES` constant
- `BlueprintsRouter::ALLOWED_FIELD_CONFIG_KEYS` constant
- `BlueprintsRouter::normalizeFields()` method
- `BlueprintsRouter::validateFieldDefinitions()` method
- `BlueprintsRouter::sanitizeFieldConfig()` method
- `BlueprintsRouter::autoCorrectFieldConfig()` method
- `GlobalsRouter::global_set` parameter
- `AssetsRouter::handle` parameter (keep `container`)
- All silent auto-correction logic
