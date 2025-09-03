<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('Get Form Submission')]
#[IsReadOnly]
class GetSubmissionTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.forms.submissions.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get a specific form submission by ID';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('form')
            ->description('Form handle')
            ->required()
            ->string('id')
            ->description('Submission ID')
            ->required();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $formHandle = $arguments['form'];
        $submissionId = $arguments['id'];

        // Validate form exists
        $form = Form::find($formHandle);
        if ($form === null) {
            return $this->createErrorResponse("Form '{$formHandle}' not found")->toArray();
        }

        // Find submission
        $submission = $form->submissions()->firstWhere('id', $submissionId);
        if (! $submission) {
            return $this->createErrorResponse("Submission '{$submissionId}' not found in form '{$formHandle}'")->toArray();
        }

        return [
            'id' => $submission->id(),
            'date' => $submission->date()->toISOString(),
            'form' => [
                'handle' => $form->handle(),
                'title' => $form->title(),
                'blueprint' => $form->blueprint()?->handle(),
            ],
            'data' => $submission->data()->all(),
            'meta' => [
                'created_at' => $submission->date()->toISOString(),
                'ip_address' => $submission->get('_ip') ?? null,
                'user_agent' => $submission->get('_user_agent') ?? null,
                'referer' => $submission->get('_referer') ?? null,
            ],
        ];
    }
}
