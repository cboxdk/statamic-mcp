<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('Export Form Submissions')]
#[IsReadOnly]
class ExportSubmissionsTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.forms.submissions.export';
    }

    protected function getToolDescription(): string
    {
        return 'Export form submissions to CSV or JSON format';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('form')
            ->description('Form handle to export submissions from')
            ->required()
            ->raw('format', [
                'type' => 'string',
                'enum' => ['csv', 'json'],
                'description' => 'Export format',
            ])
            ->optional()
            ->string('date_from')
            ->description('Export submissions from this date (Y-m-d format)')
            ->optional()
            ->string('date_to')
            ->description('Export submissions to this date (Y-m-d format)')
            ->optional()
            ->raw('fields', [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Specific fields to export (defaults to all fields)',
            ])
            ->optional()
            ->boolean('include_meta')
            ->description('Include metadata like IP, user agent, etc. (default: false)')
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
        $format = $arguments['format'] ?? 'csv';
        $dateFrom = $arguments['date_from'] ?? null;
        $dateTo = $arguments['date_to'] ?? null;
        $fields = $arguments['fields'] ?? null;
        $includeMeta = $arguments['include_meta'] ?? false;

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

        // Prepare export data
        $exportData = $submissions->map(function ($submission) use ($fields, $includeMeta) {
            $data = $submission->data()->all();

            // Filter fields if specified
            if ($fields) {
                $data = array_intersect_key($data, array_flip($fields));
            }

            // Add metadata if requested
            if ($includeMeta) {
                $data['_submission_id'] = $submission->id();
                $data['_submission_date'] = $submission->date()->toISOString();
                $data['_ip_address'] = $submission->get('_ip') ?? null;
                $data['_user_agent'] = $submission->get('_user_agent') ?? null;
                $data['_referer'] = $submission->get('_referer') ?? null;
            }

            return $data;
        })->all();

        // Generate export based on format
        if ($format === 'csv') {
            $csvContent = $this->generateCsvContent($exportData);
            $exportContent = $csvContent;
        } else {
            $exportContent = json_encode($exportData, JSON_PRETTY_PRINT);
        }

        return [
            'form' => [
                'handle' => $formHandle,
                'title' => $form->title(),
            ],
            'export' => [
                'format' => $format,
                'submission_count' => count($exportData),
                'fields_included' => $fields ? array_values($fields) : array_keys($exportData[0] ?? []),
                'content' => $exportContent,
                'filename' => $this->generateFilename($formHandle, $format),
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'include_meta' => $includeMeta,
            ],
        ];
    }

    /**
     * Generate CSV content from array data.
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    private function generateCsvContent(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new \RuntimeException('Failed to create temporary stream for CSV export');
        }

        // Write headers
        fputcsv($output, array_keys($data[0]));

        // Write data rows
        foreach ($data as $row) {
            fputcsv($output, array_values($row));
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    private function generateFilename(string $formHandle, string $format): string
    {
        $timestamp = now()->format('Y-m-d-His');

        return "submissions-{$formHandle}-{$timestamp}.{$format}";
    }
}
