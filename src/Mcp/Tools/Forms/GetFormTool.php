<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('Get Statamic Form')]
#[IsReadOnly]
class GetFormTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.forms.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific Statamic form including fields and blueprint';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Form handle')
            ->required()
            ->boolean('include_fields')
            ->description('Include form field definitions')
            ->optional()
            ->boolean('include_blueprint')
            ->description('Include blueprint structure')
            ->optional()
            ->boolean('include_submissions')
            ->description('Include form submissions data')
            ->optional()
            ->integer('submissions_limit')
            ->description('Limit number of submissions (default: 10)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $includeFields = $arguments['include_fields'] ?? true;
        $includeBlueprint = $arguments['include_blueprint'] ?? false;
        $includeSubmissions = $arguments['include_submissions'] ?? false;
        $submissionsLimit = $arguments['submissions_limit'] ?? 10;

        $form = Form::find($handle);

        if ($form === null) {
            return $this->createErrorResponse("Form '{$handle}' not found")->toArray();
        }

        try {
            $formData = [
                'handle' => $form->handle(),
                'title' => $form->title(),
                'blueprint' => $form->blueprint()?->handle(),
                'email' => $form->email(),
                'store' => $form->store(),
                'honeypot' => $form->honeypot(),
                'path' => $form->path(),
            ];

            if ($includeBlueprint && $form->blueprint()) {
                $formData['blueprint_structure'] = [
                    'handle' => $form->blueprint()->handle(),
                    'title' => $form->blueprint()->title(),
                    'fields' => $form->blueprint()->fields()->all()->map(function ($field) {
                        return [
                            'handle' => $field->handle(),
                            'type' => $field->type(),
                            'display' => $field->display(),
                            'config' => $field->config(),
                            'required' => $field->isRequired(),
                            'validation' => $field->validationRules(),
                        ];
                    })->toArray(),
                ];
            }

            if ($includeFields) {
                $formData['fields'] = [];
                if ($form->blueprint()) {
                    foreach ($form->blueprint()->fields()->all() as $field) {
                        $fieldData = [
                            'handle' => $field->handle(),
                            'type' => $field->type(),
                            'display' => $field->display(),
                            'required' => $field->isRequired(),
                            'config' => $field->config(),
                            'validation' => $field->validationRules(),
                        ];

                        $formData['fields'][] = $fieldData;
                    }
                }
            }

            if ($includeSubmissions) {
                $submissions = $form->submissions();

                if ($submissionsLimit) {
                    $submissions = $submissions->take($submissionsLimit);
                }

                $submissionData = [];
                foreach ($submissions as $submission) {
                    $submissionInfo = [
                        'id' => $submission->id(),
                        'date' => $submission->date(),
                        'data' => $submission->data(),
                    ];

                    $submissionData[] = $submissionInfo;
                }

                $formData['submissions'] = $submissionData;
                $formData['submissions_shown'] = count($submissionData);
                $formData['total_submissions'] = $form->submissions()->count();
            }

            return [
                'form' => $formData,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not retrieve form: ' . $e->getMessage())->toArray();
        }
    }
}
