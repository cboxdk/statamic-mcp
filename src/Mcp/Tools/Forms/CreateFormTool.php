<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('Create Statamic Form')]
class CreateFormTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.forms.create';
    }

    protected function getToolDescription(): string
    {
        return 'Create a new Statamic form with configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Form handle (unique identifier)')
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
            ->description('Preview changes without creating')
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
        $title = $arguments['title'] ?? ucfirst(str_replace(['_', '-'], ' ', $handle));
        $email = $arguments['email'] ?? null;
        $store = $arguments['store'] ?? true;
        $honeypot = $arguments['honeypot'] ?? 'honeypot';
        $dryRun = $arguments['dry_run'] ?? false;

        // Validate handle - check if form already exists
        try {
            $existingForm = Form::find($handle);
            // @phpstan-ignore-next-line
            if ($existingForm) {
                return $this->createErrorResponse("Form '{$handle}' already exists")->toArray();
            }
        } catch (\Exception) {
            // Form doesn't exist, which is what we want
        }

        $config = [
            'title' => $title,
            'store' => $store,
            'honeypot' => $honeypot,
        ];

        if ($email) {
            $config['email'] = $email;
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_create' => [
                    'handle' => $handle,
                    'title' => $title,
                    'config' => $config,
                ],
            ];
        }

        try {
            // Create the form
            $form = Form::make($handle);
            $form->title($title);

            // Note: Blueprint setting requires file system changes
            // This is handled separately through blueprint tools

            if ($email) {
                $form->email($email);
            }

            $form->store($store);
            $form->honeypot($honeypot);

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
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not create form: ' . $e->getMessage())->toArray();
        }
    }
}
