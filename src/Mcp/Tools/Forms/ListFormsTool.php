<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('List Statamic Forms')]
#[IsReadOnly]
class ListFormsTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.forms.list';
    }

    protected function getToolDescription(): string
    {
        return 'List all Statamic forms with metadata and configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('include_meta')
            ->description('Include metadata and configuration details')
            ->optional()
            ->string('filter')
            ->description('Filter results by name/handle')
            ->optional()
            ->integer('limit')
            ->description('Limit the number of results')
            ->optional()
            ->boolean('include_submission_count')
            ->description('Include submission count for each form')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeMeta = $arguments['include_meta'] ?? true;
        $filter = $arguments['filter'] ?? null;
        $limit = $arguments['limit'] ?? null;
        $includeSubmissionCount = $arguments['include_submission_count'] ?? true;

        $forms = [];

        try {
            $allForms = Form::all();

            foreach ($allForms as $form) {
                if ($filter && ! str_contains($form->handle(), $filter) && ! str_contains($form->title(), $filter)) {
                    continue;
                }

                $formData = [
                    'handle' => $form->handle(),
                    'title' => $form->title(),
                ];

                if ($includeMeta) {
                    $formData['blueprint'] = $form->blueprint()?->handle();
                    $formData['email'] = $form->email();
                    $formData['store'] = $form->store();
                    $formData['honeypot'] = $form->honeypot();
                    $formData['path'] = $form->path();
                }

                if ($includeSubmissionCount) {
                    $formData['submission_count'] = $form->submissions()->count();
                }

                $forms[] = $formData;
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list forms: ' . $e->getMessage())->toArray();
        }

        if ($limit) {
            $forms = array_slice($forms, 0, $limit);
        }

        return [
            'forms' => $forms,
            'count' => count($forms),
            'total_available' => Form::all()->count(),
        ];
    }
}
