<?php

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Services\DocumentationContentService;
use Cboxdk\StatamicMcp\Mcp\Tools\Services\DocumentationMapService;
use Cboxdk\StatamicMcp\Mcp\Tools\Services\DocumentationSearchService;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DocsSystemTool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

describe('Refactored Tools', function () {

    describe('BaseStatamicTool', function () {
        it('is abstract and cannot be instantiated directly', function () {
            $reflection = new ReflectionClass(BaseStatamicTool::class);
            expect($reflection->isAbstract())->toBeTrue();
        });

        it('has required abstract methods', function () {
            $reflection = new ReflectionClass(BaseStatamicTool::class);
            $abstractMethods = array_map(fn ($method) => $method->getName(),
                array_filter($reflection->getMethods(), fn ($method) => $method->isAbstract())
            );

            expect($abstractMethods)->toContain('getToolName');
            expect($abstractMethods)->toContain('getToolDescription');
            expect($abstractMethods)->toContain('defineSchema');
            expect($abstractMethods)->toContain('execute');
        });
    });

    describe('DocsSystemTool', function () {
        beforeEach(function () {
            $this->tool = new DocsSystemTool;
        });

        it('extends BaseStatamicTool', function () {
            expect($this->tool)->toBeInstanceOf(BaseStatamicTool::class);
        });

        it('has correct tool details', function () {
            expect($this->tool->name())->toBe('statamic.system.docs');
            expect($this->tool->description())->toContain('Search and retrieve Statamic documentation');
        });

        it('defines schema correctly', function () {
            $schema = new ToolInputSchema;
            $definedSchema = $this->tool->schema($schema);

            expect($definedSchema)->toBeInstanceOf(ToolInputSchema::class);
        });

        it('handles tool execution', function () {
            $result = $this->tool->handle(['query' => 'collections']);

            $resultData = $result->toArray();
            $jsonContent = $resultData['content'][0]['text'];
            $data = json_decode($jsonContent, true);
            expect($data)->toBeArray();
            expect($data)->toHaveKey('success');
        });

        it('handles empty queries', function () {
            $result = $this->tool->handle(['query' => '']);

            $resultData = $result->toArray();
            $jsonContent = $resultData['content'][0]['text'];
            $data = json_decode($jsonContent, true);

            expect($data)->toBeArray();
            expect($data)->toHaveKey('success');
            expect($data['success'])->toBeTrue();
            expect($data)->toHaveKey('data');
            expect($data['data'])->toHaveKey('results_count');
            expect($data['data']['results_count'])->toBe(0);
        });
    });

    // FieldsetsScanStructuresTool was refactored into single-purpose fieldset tools
});

describe('Documentation Services', function () {

    describe('DocumentationSearchService', function () {
        beforeEach(function () {
            $this->service = new DocumentationSearchService;
        });

        it('can perform search with results', function () {
            $results = $this->service->search('collections', null, 5, false);

            expect($results)->toBeArray();
            expect(count($results))->toBeGreaterThan(0);

            if (count($results) > 0) {
                $firstResult = $results[0];
                expect($firstResult)->toHaveKey('title');
                expect($firstResult)->toHaveKey('url');
                expect($firstResult)->toHaveKey('section');
                expect($firstResult)->toHaveKey('relevance_score');
            }
        });

        it('returns empty results for empty query', function () {
            $results = $this->service->search('', null, 5, false);
            expect($results)->toBe([]);
        });

        it('provides search suggestions', function () {
            $suggestions = $this->service->getSearchSuggestions('unknown_term');

            expect($suggestions)->toBeArray();
            expect(count($suggestions))->toBeGreaterThan(0);
        });

        it('limits results correctly', function () {
            $results = $this->service->search('field', null, 3, false);
            expect(count($results))->toBeLessThanOrEqual(3);
        });
    });

    describe('DocumentationMapService', function () {
        beforeEach(function () {
            $this->service = new DocumentationMapService;
        });

        it('returns documentation map', function () {
            $docMap = $this->service->getDocumentationMap(null);

            expect($docMap)->toBeArray();
            expect(count($docMap))->toBeGreaterThan(0);

            // Check that entries have required structure
            $firstEntry = array_values($docMap)[0];
            expect($firstEntry)->toHaveKey('title');
            expect($firstEntry)->toHaveKey('section');
            expect($firstEntry)->toHaveKey('summary');
            expect($firstEntry)->toHaveKey('tags');
            expect($firstEntry)->toHaveKey('keywords');
        });

        it('filters by section', function () {
            $coreMap = $this->service->getDocumentationMap('core');

            expect($coreMap)->toBeArray();

            foreach ($coreMap as $doc) {
                expect($doc['section'])->toBe('core');
            }
        });
    });

    describe('DocumentationContentService', function () {
        beforeEach(function () {
            $this->service = new DocumentationContentService;
        });

        it('can fetch content for documentation', function () {
            $docInfo = [
                'title' => 'Test Documentation',
                'section' => 'core',
                'summary' => 'Test summary',
            ];

            $content = $this->service->fetchContent('https://example.com/test', $docInfo);

            expect($content)->toBeString();
            expect(strlen($content))->toBeGreaterThan(0);
        });

        it('generates appropriate mock content for different sections', function () {
            $tagDoc = [
                'title' => 'Collection Tag',
                'section' => 'tags',
                'tags' => ['tag', 'collection'],
            ];

            $content = $this->service->fetchContent('', $tagDoc);
            expect($content)->toContain('Collection Tag');
            expect($content)->toContain('Usage');
        });
    });
});
