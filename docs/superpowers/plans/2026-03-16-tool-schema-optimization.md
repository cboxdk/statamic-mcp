# Tool Schema Optimization Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Optimize all MCP tool schemas so LLMs send correct payloads on the first try — expanded descriptions, per-action required params, strict validation via Statamic APIs, and `type` → `resource_type` rename.

**Architecture:** Systematic refactor of all 9 router tools. Each router gets: improved descriptions, action-specific documentation, Statamic API delegation for validation, and strict error responses. The `type` → `resource_type` rename affects BaseRouter + 3 routers that use it for routing (StructuresRouter, AssetsRouter, UsersRouter).

**Tech Stack:** PHP 8.3, Laravel 12, Statamic v6, PHPStan Level 8, Pest 4

**Spec:** `docs/superpowers/specs/2026-03-16-tool-schema-optimization-design.md`

---

## Chunk 1: BaseRouter rename + description infrastructure

### Task 1: Rename `type` → `resource_type` in BaseRouter

**Files:**
- Modify: `src/Mcp/Tools/BaseRouter.php`
- Modify: All test files that reference `'type' =>`

- [ ] **Step 1: Update BaseRouter defineSchema()**

Change `'type'` to `'resource_type'` in the schema definition. Update the description:
```php
'resource_type' => JsonSchema::string()
    ->description('Resource subtype for routers that manage multiple resource kinds. See the specific tool description for valid values.')
```

- [ ] **Step 2: Find and update ALL test files**

Run: `grep -rn "'type' =>" tests/ | grep -v node_modules` to find every test that passes `type`. Replace with `resource_type` in all matches.

- [ ] **Step 3: Run tests** — expect failures in routers that read `$arguments['type']`
- [ ] **Step 4: Update StructuresRouter** — change `$arguments['type']` → `$arguments['resource_type']` in `executeAction()` and `getRequiredPermissions()`
- [ ] **Step 5: Update AssetsRouter** — same change
- [ ] **Step 6: Update UsersRouter** — same change
- [ ] **Step 7: Update SystemRouter** — update schema if it re-declares `type`
- [ ] **Step 8: Update all Concerns traits** that read `$arguments['type']`: grep for it in `src/Mcp/Tools/Routers/Concerns/`
- [ ] **Step 9: Run full test suite**: `./vendor/bin/pest`
- [ ] **Step 10: PHPStan + Pint**
- [ ] **Step 11: Commit**: `git commit -m "refactor: rename type to resource_type across all routers and tests"`

---

## Chunk 2: Schema descriptions for all routers

### Task 2: EntriesRouter schema optimization

**Files:**
- Modify: `src/Mcp/Tools/Routers/EntriesRouter.php` — `defineSchema()` only

Update all parameter descriptions. Key changes:

```php
'action' => JsonSchema::string()
    ->description(
        'Action to perform. Required params per action: '
        . 'list (collection; optional: limit, offset, filters, include_unpublished), '
        . 'get (collection, id), '
        . 'create (collection, data — use statamic-blueprints action "get" to see field structure first), '
        . 'update (collection, id, data), '
        . 'delete (collection, id), '
        . 'publish (collection, id), '
        . 'unpublish (collection, id)'
    )
    ->enum([...])
    ->required(),

'collection' => JsonSchema::string()
    ->description('Collection handle in snake_case. Required for all actions. Example: "blog", "products"')
    ->required(),

'id' => JsonSchema::string()
    ->description('Entry UUID. Required for get, update, delete, publish, unpublish actions'),

'site' => JsonSchema::string()
    ->description('Site handle for multi-site setups. Defaults to the default site. Example: "default", "en", "da"'),

'data' => JsonSchema::object()
    ->description(
        'Entry field values. Structure must match the collection blueprint including nested types '
        . '(bard, replicator, grid). Use statamic-blueprints action "get" with the collection\'s '
        . 'blueprint handle to see required fields, types, and nesting before sending data.'
    ),

'filters' => JsonSchema::object()
    ->description('Filter conditions as key-value pairs. Keys are field handles from the blueprint. Example: {"status": "published", "author": "john"}'),

'include_unpublished' => JsonSchema::boolean()
    ->description('Include draft/unpublished entries in list results. Default: false'),

'limit' => JsonSchema::integer()
    ->description('Maximum results to return (default: 100, max: 500)'),

'offset' => JsonSchema::integer()
    ->description('Number of results to skip for pagination. Use with limit for paging'),
```

- [ ] **Step 1: Update defineSchema()** with all new descriptions
- [ ] **Step 2: PHPStan + Pint**
- [ ] **Step 3: Run tests**: `./vendor/bin/pest tests/Feature/Routers/EntriesRouterTest.php`
- [ ] **Step 4: Commit**: `git commit -m "refactor: optimize EntriesRouter schema descriptions"`

---

### Task 3: TermsRouter schema optimization

Same pattern as EntriesRouter. Key description changes:

- `action`: list required params per action (list needs taxonomy, get needs taxonomy+id/slug, create needs taxonomy+data, etc.)
- `taxonomy`: "Taxonomy handle in snake_case. Required for all actions. Example: \"tags\", \"categories\""
- `id`/`slug`: expanded with action context
- `data`: discovery-first instruction referencing taxonomy blueprint
- `filters`, `limit`, `offset`, `site`: same expanded descriptions as EntriesRouter

- [ ] **Step 1-4: Same pattern** — update, PHPStan, test, commit

---

### Task 4: GlobalsRouter schema optimization + `global_set` deprecation

**Files:**
- Modify: `src/Mcp/Tools/Routers/GlobalsRouter.php` — `defineSchema()` + `executeAction()`

- Deprecate `global_set`: keep it in schema but mark description as deprecated, add fallback logic
- `action`: "Required params per action: list (optional: limit, offset), get (handle), update (handle, data)"
- `handle`: "Global set handle. Example: \"site_settings\", \"seo\""
- `data`: discovery-first instruction
- Remove `global_set` duplication by making `handle` the primary and `global_set` a deprecated alias

- [ ] **Step 1-4: Same pattern**

---

### Task 5: BlueprintsRouter schema optimization

**Files:**
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php` — `defineSchema()` only (validation changes in Chunk 3)

Key description changes:
- `action`: list required params per action (create needs handle+namespace+fields, get needs handle+namespace, etc.)
- `handle`: "Blueprint identifier in snake_case. Example: \"blog_post\", \"product\""
- `namespace`: "Blueprint namespace determining storage location. Must match a valid Statamic content type."
- `collection_handle`/`taxonomy_handle`: expanded with when-required context
- `fields`: "Array of field definition objects. Each MUST have \"handle\" (string) and \"field\" (object with at minimum a \"type\" key). Use statamic-schema tool to see available field types. Example: [{\"handle\": \"title\", \"field\": {\"type\": \"text\", \"display\": \"Title\"}}]"
- `output_format`: expanded description

- [ ] **Step 1-4: Same pattern**

---

### Task 6: StructuresRouter schema optimization

- `action`: list required params per action per resource_type
- `resource_type`: already has enum, expand description
- `handle`: expanded per resource_type context
- `data`: discovery-first instruction per resource_type

- [ ] **Step 1-4: Same pattern**

---

### Task 7: AssetsRouter schema optimization + `handle` deprecation

- Deprecate `handle` param (keep as alias for `container`)
- `action`: list required params per action per resource_type (container vs asset)
- `container`: expanded description
- `path`, `destination`, `data`: expanded descriptions
- `encoding`: already good, keep

- [ ] **Step 1-4: Same pattern**

---

### Task 8: UsersRouter schema optimization

- `action`: list required params per action per resource_type (user/role/group)
- `resource_type`: expand description with context
- `data`: "User/role/group data. For users: requires \"email\" for create. Use statamic-users action \"get\" to see current data structure. For roles: requires \"handle\" and \"title\". For groups: requires \"handle\"."
- `email`, `handle`, `role`, `query`, `status`, `super`, `group`: expanded descriptions

- [ ] **Step 1-4: Same pattern**

---

### Task 9: SystemRouter + ContentFacadeRouter schema optimization

SystemRouter:
- `action`: list required params (info needs nothing, cache_clear needs cache_type, config_get needs config_key, etc.)
- `cache_type`, `config_key`, `config_value`: expanded descriptions

ContentFacadeRouter:
- `workflow`: expand description of what each workflow does
- `filters`: expand with available filter keys

- [ ] **Step 1-4: Same pattern**

---

## Chunk 3: Statamic API delegation + strict validation

### Task 10: BlueprintsRouter — replace custom validation with Statamic APIs

**Files:**
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php`
- Modify/Create: `tests/Feature/Routers/BlueprintsRouterValidationTest.php`

This is the biggest single change. Remove:
- `ALLOWED_FIELD_TYPES` constant
- `ALLOWED_FIELD_CONFIG_KEYS` constant
- `normalizeFields()` method
- `validateFieldDefinitions()` method
- `sanitizeFieldConfig()` method
- `autoCorrectFieldConfig()` method

Replace with:
- Use `FieldtypeRepository::find($type)` for fieldtype validation — returns available types on error
- Validate field structure strictly: each field must have `handle` + `field` object with `type` key. Return instructive error if flat format detected.
- Use `Blueprint::setContents()` for tab/section normalization after validation passes
- Add `PARAM_CORRECTIONS` constant for near-miss suggestions

New validation flow for `createBlueprint()`:
```
1. Validate handle, namespace, collection_handle/taxonomy_handle (existing)
2. For each field in fields array:
   a. Validate {handle, field: {type, ...}} structure — error if flat
   b. Validate fieldtype via FieldtypeRepository::find() — list available types on error
   c. Near-miss check on field config keys — suggest corrections
3. Build contents array with title + validated fields
4. Blueprint::setContents() for normalization
5. Save
```

Tests:
- Flat field format returns instructive error with correct format example
- Unknown fieldtype returns available types list
- Missing `field` key returns error showing correct structure
- Valid fields create blueprint successfully
- Near-miss param ("taxonomy" → "taxonomies") returns suggestion

- [ ] **Step 1: Write validation tests**
- [ ] **Step 2: Run tests — verify fail**
- [ ] **Step 3: Refactor createBlueprint() with Statamic APIs**
- [ ] **Step 4: Remove dead methods and constants**
- [ ] **Step 5: Run tests — verify pass**
- [ ] **Step 6: PHPStan + Pint**
- [ ] **Step 7: Run full test suite** (blueprint tests may need updating)
- [ ] **Step 8: Commit**: `git commit -m "refactor: BlueprintsRouter uses Statamic APIs for validation, removes auto-correction"`

---

### Task 11: EntriesRouter — validate data against blueprint via FieldsValidator

**Files:**
- Modify: `src/Mcp/Tools/Routers/EntriesRouter.php` (or its Concerns trait for entry creation)

For `create` and `update` actions, add validation before saving:
```php
use Statamic\Fields\Validator as FieldsValidator;

$blueprint = $collection->entryBlueprint();
$fields = $blueprint->fields()->addValues($data);
$fieldsValidator = (new FieldsValidator)->fields($fields);

try {
    $validatedData = $fieldsValidator->validate();
} catch (ValidationException $e) {
    return $this->createErrorResponse(
        'Validation failed. ' . collect($e->errors())
            ->map(fn($msgs, $key) => "$key: " . implode(', ', $msgs))
            ->implode('; ')
    )->toArray();
}
```

Read the existing create/update entry methods first to understand current flow, then add validation.

- [ ] **Step 1: Read current entry create/update methods**
- [ ] **Step 2: Add FieldsValidator validation before save**
- [ ] **Step 3: Write test for invalid data returning instructive error**
- [ ] **Step 4: Run tests**
- [ ] **Step 5: PHPStan + Pint**
- [ ] **Step 6: Commit**: `git commit -m "refactor: validate entry data against blueprint via Statamic FieldsValidator"`

---

### Task 12: TermsRouter + GlobalsRouter — same validation pattern

Apply the same FieldsValidator pattern to:
- TermsRouter create/update (validate against taxonomy blueprint)
- GlobalsRouter update (validate against global set blueprint)

- [ ] **Step 1-6: Same pattern as Task 11**
- [ ] **Commit**: `git commit -m "refactor: validate terms and globals data against blueprints"`

---

### Task 13: Add near-miss parameter suggestion helper

**Files:**
- Create or modify: `src/Mcp/Tools/Concerns/RouterHelpers.php` — add a `suggestCorrection()` method

```php
private const PARAM_CORRECTIONS = [
    'taxonomy' => 'taxonomies',
    'collection' => 'collections',
    'field_type' => 'type',
    'fieldtype' => 'type',
    'name' => 'handle',
];

protected function suggestCorrection(string $key): ?string
{
    return self::PARAM_CORRECTIONS[$key] ?? null;
}
```

Use in BlueprintsRouter field config validation to suggest corrections for unknown keys.

- [ ] **Step 1: Add method + constant**
- [ ] **Step 2: Integrate into BlueprintsRouter field config validation**
- [ ] **Step 3: Write test**
- [ ] **Step 4: Commit**: `git commit -m "feat: add near-miss parameter correction suggestions"`

---

## Chunk 4: Final integration + validation

### Task 14: Full test suite pass

- [ ] **Step 1: Run full test suite**: `./vendor/bin/pest`
- [ ] **Step 2: Fix any failures** from the cumulative changes
- [ ] **Step 3: Run PHPStan**: `./vendor/bin/phpstan analyse`
- [ ] **Step 4: Run Pint**: `./vendor/bin/pint`
- [ ] **Step 5: Commit fixes**

---

### Task 15: Build frontend + deploy to sandbox

- [ ] **Step 1: Build**: `npx vite build`
- [ ] **Step 2: Copy to sandbox**: `cp resources/dist/build/assets/*.js /Users/sylvester/Projects/Cbox/statamic6-sandbox/public/vendor/statamic-mcp/build/assets/ && cp resources/dist/build/manifest.json /Users/sylvester/Projects/Cbox/statamic6-sandbox/public/vendor/statamic-mcp/build/manifest.json`
- [ ] **Step 3: Verify sandbox works**: `cd /Users/sylvester/Projects/Cbox/statamic6-sandbox && php artisan mcp:start statamic` — verify tools list shows `resource_type` not `type`
