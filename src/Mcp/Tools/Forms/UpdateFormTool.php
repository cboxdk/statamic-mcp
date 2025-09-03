<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('Update Statamic Form')]
class UpdateFormTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.forms.update';
    }

    protected function getToolDescription(): string
    {
        return 'Update an existing Statamic form configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Form handle')
            ->required()
            ->string('title')
            ->description('Form title')
            ->optional()
            ->raw('email', [
                'type' => 'array',
                'description' => 'Email configuration for form submissions',
                'properties' => [
                    'to' => ['type' => 'string'],
                    'from' => ['type' => 'string'],
                    'subject' => ['type' => 'string'],
                    'template' => ['type' => 'string'],
                ],
            ])
            ->optional()
            ->boolean('store')
            ->description('Whether to store submissions')
            ->optional()
            ->string('honeypot')
            ->description('Honeypot field name for spam protection')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview changes without updating')
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
        $newTitle = $arguments['title'] ?? null;
        $newEmail = $arguments['email'] ?? null;
        $newStore = $arguments['store'] ?? null;
        $newHoneypot = $arguments['honeypot'] ?? null;
        $dryRun = $arguments['dry_run'] ?? false;

        $form = Form::find($handle);

        if ($form === null) {
            return $this->createErrorResponse("Form '{$handle}' not found")->toArray();
        }

        $changes = [];

        // Check for title change
        if ($newTitle !== null && $newTitle !== $form->title()) {
            $changes['title'] = ['from' => $form->title(), 'to' => $newTitle];
        }

        // Note: Blueprint changes are complex and require file system handling
        // This functionality is omitted for now

        // Check for email change
        if ($newEmail !== null && $newEmail !== $form->email()) {
            $changes['email'] = ['from' => $form->email(), 'to' => $newEmail];
        }

        // Check for store change
        if ($newStore !== null && $newStore !== $form->store()) {
            $changes['store'] = ['from' => $form->store(), 'to' => $newStore];
        }

        // Check for honeypot change
        if ($newHoneypot !== null && $newHoneypot !== $form->honeypot()) {
            $changes['honeypot'] = ['from' => $form->honeypot(), 'to' => $newHoneypot];
        }

        if (count($changes) === 0) {
            return [
                'handle' => $handle,
                'message' => 'No changes detected',
                'form' => [
                    'handle' => $form->handle(),
                    'title' => $form->title(),
                    'blueprint' => $form->blueprint()?->handle(),
                    'email' => $form->email(),
                    'store' => $form->store(),
                    'honeypot' => $form->honeypot(),
                ],
            ];
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'handle' => $handle,
                'proposed_changes' => $changes,
                'has_changes' => true,
            ];
        }

        try {
            // Apply updates
            if (isset($changes['title'])) {
                $form->title($newTitle);
            }

            // Note: Blueprint changes require more complex handling
            // and typically involve file system changes
            // This is left for future implementation

            if (isset($changes['email'])) {
                $form->email($newEmail);
            }

            if (isset($changes['store'])) {
                $form->store($newStore);
            }

            if (isset($changes['honeypot'])) {
                $form->honeypot($newHoneypot);
            }

            $form->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('form_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'form' => [
                    'handle' => $form->handle(),
                    'title' => $form->title(),
                    'blueprint' => $form->blueprint()?->handle(),
                    'email' => $form->email(),
                    'store' => $form->store(),
                    'honeypot' => $form->honeypot(),
                    'path' => $form->path(),
                ],
                'changes' => $changes,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not update form: ' . $e->getMessage())->toArray();
        }
    }
}
