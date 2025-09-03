<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Scan Fieldsets')]
#[IsReadOnly]
class ScanFieldsetsTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.fieldsets.scan';
    }

    protected function getToolDescription(): string
    {
        return 'Scan and analyze fieldsets with field relationships and usage patterns';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_fields')
            ->description('Include field definitions')
            ->optional()
            ->boolean('include_usage')
            ->description('Include usage information where fieldsets are used')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeFields = $arguments['include_fields'] ?? true;
        $includeUsage = $arguments['include_usage'] ?? false;

        try {
            $fieldsetPaths = glob(resource_path('fieldsets/*.yaml'));

            if ($fieldsetPaths === false) {
                $fieldsetPaths = [];
            }

            $fieldsets = [];

            foreach ($fieldsetPaths as $path) {
                $handle = basename($path, '.yaml');
                $fieldsetData = [
                    'handle' => $handle,
                    'path' => $path,
                ];

                if ($includeFields) {
                    $yaml = file_get_contents($path);
                    if ($yaml !== false) {
                        $data = yaml_parse($yaml);
                        if ($data && isset($data['fields'])) {
                            $fieldsetData['fields'] = $data['fields'];
                        }
                    }
                }

                if ($includeUsage) {
                    $fieldsetData['usage'] = $this->findFieldsetUsage($handle);
                }

                $fieldsets[] = $fieldsetData;
            }

            return [
                'fieldsets' => $fieldsets,
                'count' => count($fieldsets),
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to scan fieldsets: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Find where a fieldset is being used.
     *
     *
     * @return array<string, mixed>
     */
    private function findFieldsetUsage(string $handle): array
    {
        // This would scan blueprints for fieldset references
        // For now, return empty usage
        return [
            'blueprints' => [],
            'count' => 0,
        ];
    }
}
