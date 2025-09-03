<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('Delete Statamic Form')]
class DeleteFormTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.forms.delete';
    }

    protected function getToolDescription(): string
    {
        return 'Delete a Statamic form with safety checks';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Form handle')
            ->required()
            ->boolean('force')
            ->description('Force deletion without safety checks')
            ->optional()
            ->boolean('delete_submissions')
            ->description('Also delete all form submissions')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview what would be deleted without actually deleting')
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
        $force = $arguments['force'] ?? false;
        $deleteSubmissions = $arguments['delete_submissions'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        $form = Form::find($handle);

        if ($form === null) {
            return $this->createErrorResponse("Form '{$handle}' not found")->toArray();
        }

        // Safety checks - check if form has submissions
        $warnings = [];
        $usage = $this->checkFormUsage($form);

        if ($usage['submission_count'] > 0) {
            $warnings[] = "Form has {$usage['submission_count']} submission(s)";
            if (! $deleteSubmissions) {
                $warnings[] = 'Submissions will be preserved unless delete_submissions=true';
            }
        }

        if (! empty($warnings) && ! $force && ! $dryRun && $usage['submission_count'] > 0 && ! $deleteSubmissions) {
            return $this->createErrorResponse(
                'Cannot delete form with submissions. ' . implode('. ', $warnings) . '. Use force=true to override or delete_submissions=true to remove submissions.'
            )->toArray();
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_delete' => [
                    'handle' => $handle,
                    'title' => $form->title(),
                    'blueprint' => $form->blueprint()?->handle(),
                    'submission_count' => $usage['submission_count'],
                    'will_delete_submissions' => $deleteSubmissions,
                ],
                'warnings' => $warnings,
                'usage' => $usage,
            ];
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

            // Delete submissions if requested
            if ($deleteSubmissions && $usage['submission_count'] > 0) {
                $submissions = $form->submissions();
                foreach ($submissions->all() as $submission) {
                    $submission->delete();
                }
            }

            // Delete form
            $form->delete();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('form_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'deleted' => $formData,
                'warnings' => $warnings,
                'submissions_deleted' => $deleteSubmissions ? $usage['submission_count'] : 0,
                'usage_at_deletion' => $usage,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not delete form: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Check where the form is being used.
     *
     * @param  mixed  $form
     *
     * @return array<string, mixed>
     */
    private function checkFormUsage($form): array
    {
        $usage = [
            'submission_count' => 0,
        ];

        try {
            $usage['submission_count'] = $form->submissions()->count();
        } catch (\Exception) {
            // Silently ignore errors in usage checking
        }

        return $usage;
    }
}
