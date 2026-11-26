<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\ToolSurface;

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

/**
 * One vocabulary for every built-in tool. v3 named the same thing several
 * ways (`global_set` and `handle`, `collection_handle` and `collection`,
 * `config` and `data`), so an agent that learned one tool guessed wrong on
 * the next. A new argument name goes on this list deliberately, or the test
 * fails; a synonym for a listed name should not.
 */
class ArgumentNamesTest extends TestCase
{
    private const VOCABULARY = [
        // Every tool
        'action', 'resource_type', 'approval_id', 'idempotency_key',
        // What a call addresses
        'collection', 'taxonomy', 'container', 'form', 'namespace', 'handle', 'id', 'email', 'slug', 'path', 'site', 'field',
        // What a write carries
        'data', 'fields', 'title', 'replace_fields', 'merge_sets', 'expected_version', 'revision_message', 'revision_id', 'reason', 'role',
        'destination', 'filename', 'folder', 'content', 'encoding', 'source_url', 'file_path', 'config_key', 'cache_type', 'urls',
        // Reading and paging
        'limit', 'cursor', 'filters', 'query', 'status', 'super', 'group', 'recursive', 'revision_state', 'include_unpublished',
        'include_counts', 'include_details', 'include_fields', 'include_config', 'include_format_spec', 'include_permissions',
        'include_performance', 'include_tree', 'max_format_depth', 'output_format', 'scope', 'severity', 'max_findings',
    ];

    /** @return list<BaseRouter> */
    private function builtInFacets(): array
    {
        return array_values(array_filter(
            StatamicMcpServer::presented([...StatamicMcpServer::CORE_TOOLS, ...StatamicMcpServer::SEARCHABLE_TOOLS]),
            fn (mixed $tool): bool => $tool instanceof BaseRouter,
        ));
    }

    public function test_every_argument_is_in_the_vocabulary(): void
    {
        $facets = $this->builtInFacets();
        $this->assertNotEmpty($facets);

        foreach ($facets as $tool) {
            $names = array_map('strval', array_keys($tool->schema(new JsonSchemaTypeFactory)));
            $this->assertSame([], array_values(array_diff($names, self::VOCABULARY)), "{$tool->name()} has arguments outside the vocabulary");
            foreach ($names as $name) {
                $this->assertDoesNotMatchRegularExpression('/_handle$/', $name, "{$tool->name()}: a handle argument is named after what it addresses");
            }
        }
    }

    public function test_every_built_in_action_declares_what_it_reads(): void
    {
        foreach ($this->builtInFacets() as $tool) {
            foreach ($tool->declaredActions() as $action) {
                $this->assertTrue($action->declaresArguments(), "{$tool->name()} {$action->name} declares no arguments, so it would accept anything");
            }
        }
    }

    public function test_resource_types_are_singular_snake_case(): void
    {
        foreach ($this->builtInFacets() as $tool) {
            foreach ($tool->resourceTypes() as $type) {
                $this->assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $type, $tool->name());
                $this->assertStringEndsNotWith('s', $type, "{$tool->name()}: resource_type '{$type}' is plural");
            }
        }
    }

    public function test_each_tool_offers_only_what_its_actions_take(): void
    {
        // The read tool must not offer write arguments, nor the reverse:
        // the schema is the union of what the tool's own actions read.
        foreach ($this->builtInFacets() as $tool) {
            $taken = ['action' => true];
            foreach ($tool->declaredActions() as $action) {
                if ($action->writes() !== ($tool->presentedFacet() === 'write')) {
                    continue;
                }
                $taken += array_fill_keys($action->argumentNames(), true);
                if ($action->writes()) {
                    $taken += ['approval_id' => true, 'idempotency_key' => true];
                }
            }
            if ($tool->resourceTypes() !== []) {
                $taken['resource_type'] = true;
            }

            $this->assertEqualsCanonicalizing(
                array_keys($taken),
                array_map('strval', array_keys($tool->schema(new JsonSchemaTypeFactory))),
                $tool->name(),
            );
        }
    }
}
