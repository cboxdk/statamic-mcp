<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('Delete Form Submission')]
class DeleteSubmissionTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.forms.submissions.delete';
    }

    protected function getToolDescription(): string
    {
        return 'Delete a form submission permanently';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->addDryRunSchema($schema)
            ->string('form')
            ->description('Form handle')
            ->required()
            ->string('id')
            ->description('Submission ID to delete')
            ->required()
            ->boolean('create_backup')
            ->description('Create backup before deletion (default: true)')
            ->optional();
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
        $dryRun = $arguments['dry_run'] ?? false;
        $createBackup = $arguments['create_backup'] ?? true;

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

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_delete' => [
                    'id' => $submission->id(),
                    'form' => $formHandle,
                    'date' => $submission->date()->toISOString(),
                    'data_fields' => count($submission->data()->all()),
                    'backup_created' => $createBackup,
                ],
            ];
        }

        // Create backup if requested
        $backupInfo = null;
        if ($createBackup) {
            $backupInfo = [
                'id' => $submission->id(),
                'form' => $formHandle,
                'data' => $submission->data()->all(),
                'date' => $submission->date()->toISOString(),
                'backup_created_at' => now()->toISOString(),
            ];
        }

        try {
            // Store submission data before deletion
            $submissionData = [
                'id' => $submission->id(),
                'form' => $formHandle,
                'date' => $submission->date()->toISOString(),
                'data_fields' => count($submission->data()->all()),
            ];

            // Delete the submission
            $submission->delete();

            // Clear caches
            $cacheResult = $this->clearStatamicCaches(['stache']);

            return [
                'success' => true,
                'deleted' => $submissionData,
                'backup' => $backupInfo,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete submission: {$e->getMessage()}")->toArray();
        }
    }
}
