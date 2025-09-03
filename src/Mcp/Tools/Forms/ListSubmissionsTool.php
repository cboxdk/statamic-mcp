<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('List Form Submissions')]
#[IsReadOnly]
class ListSubmissionsTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.forms.submissions.list';
    }

    protected function getToolDescription(): string
    {
        return 'List form submissions with filtering and pagination';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('form')
            ->description('Form handle to list submissions from')
            ->required()
            ->integer('limit')
            ->description('Maximum number of submissions to return (default: 50)')
            ->optional()
            ->integer('offset')
            ->description('Number of submissions to skip (default: 0)')
            ->optional()
            ->string('date_from')
            ->description('Filter submissions from this date (Y-m-d format)')
            ->optional()
            ->string('date_to')
            ->description('Filter submissions to this date (Y-m-d format)')
            ->optional()
            ->boolean('include_data')
            ->description('Include submission data fields (default: true)')
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
        $limit = $arguments['limit'] ?? 50;
        $offset = $arguments['offset'] ?? 0;
        $dateFrom = $arguments['date_from'] ?? null;
        $dateTo = $arguments['date_to'] ?? null;
        $includeData = $arguments['include_data'] ?? true;

        // Validate form exists
        $form = Form::find($formHandle);
        if ($form === null) {
            return $this->createErrorResponse("Form '{$formHandle}' not found")->toArray();
        }

        // Get submissions
        $submissions = $form->submissions();

        // Apply date filters
        if ($dateFrom) {
            $submissions = $submissions->filter(function ($submission) use ($dateFrom) {
                return $submission->date() >= now()->parse($dateFrom);
            });
        }

        if ($dateTo) {
            $submissions = $submissions->filter(function ($submission) use ($dateTo) {
                return $submission->date() <= now()->parse($dateTo)->endOfDay();
            });
        }

        $totalCount = $submissions->count();
        $pagedSubmissions = $submissions->skip($offset)->take($limit);

        $submissionData = $pagedSubmissions->map(function ($submission) use ($includeData) {
            $result = [
                'id' => $submission->id(),
                'date' => $submission->date()->toISOString(),
                'form_handle' => $submission->form()->handle(),
                'form_title' => $submission->form()->title(),
            ];

            if ($includeData) {
                $result['data'] = $submission->data()->all();
            }

            return $result;
        })->all();

        return [
            'form' => [
                'handle' => $formHandle,
                'title' => $form->title(),
            ],
            'submissions' => $submissionData,
            'total_count' => $totalCount,
            'returned_count' => count($submissionData),
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($submissionData)) < $totalCount,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ];
    }
}
