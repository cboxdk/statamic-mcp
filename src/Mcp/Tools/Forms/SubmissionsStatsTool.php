<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Forms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Form;

#[Title('Form Submissions Statistics')]
#[IsReadOnly]
class SubmissionsStatsTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.forms.submissions.stats';
    }

    protected function getToolDescription(): string
    {
        return 'Get statistics and analytics for form submissions';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('form')
            ->description('Form handle to get statistics for')
            ->required()
            ->raw('period', [
                'type' => 'string',
                'enum' => ['last_7_days', 'last_30_days', 'last_year', 'all_time'],
                'description' => 'Time period for statistics',
            ])
            ->optional()
            ->boolean('include_field_stats')
            ->description('Include field-level statistics (default: true)')
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
        $period = $arguments['period'] ?? 'all_time';
        $includeFieldStats = $arguments['include_field_stats'] ?? true;

        // Validate form exists
        $form = Form::find($formHandle);
        if ($form === null) {
            return $this->createErrorResponse("Form '{$formHandle}' not found")->toArray();
        }

        // Get submissions based on period
        $submissions = $form->submissions();
        $filteredSubmissions = $this->filterSubmissionsByPeriod($submissions, $period);

        // Calculate basic statistics
        $totalCount = $submissions->count();
        $periodCount = $filteredSubmissions->count();

        $stats = [
            'form' => [
                'handle' => $formHandle,
                'title' => $form->title(),
            ],
            'period' => $period,
            'counts' => [
                'total_all_time' => $totalCount,
                'total_in_period' => $periodCount,
            ],
            'dates' => [
                'first_submission' => $submissions->first()?->date()?->toISOString(),
                'latest_submission' => $submissions->last()?->date()?->toISOString(),
            ],
        ];

        // Add daily breakdown for recent periods
        if (in_array($period, ['last_7_days', 'last_30_days'])) {
            $stats['daily_breakdown'] = $this->getDailyBreakdown($filteredSubmissions, $period);
        }

        // Add field statistics if requested
        if ($includeFieldStats && $periodCount > 0) {
            $stats['field_statistics'] = $this->getFieldStatistics($filteredSubmissions);
        }

        // Add hourly distribution
        if ($periodCount > 0) {
            $stats['hourly_distribution'] = $this->getHourlyDistribution($filteredSubmissions);
        }

        return $stats;
    }

    /**
     * Filter submissions by time period.
     *
     * @param  mixed  $submissions
     *
     * @return mixed
     */
    private function filterSubmissionsByPeriod($submissions, string $period)
    {
        return match ($period) {
            'last_7_days' => $submissions->filter(fn ($s) => $s->date() >= now()->subDays(7)),
            'last_30_days' => $submissions->filter(fn ($s) => $s->date() >= now()->subDays(30)),
            'last_year' => $submissions->filter(fn ($s) => $s->date() >= now()->subYear()),
            default => $submissions,
        };
    }

    /**
     * Get daily submission breakdown.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $submissions
     *
     * @return array<string, int>
     */
    private function getDailyBreakdown($submissions, string $period): array
    {
        $days = $period === 'last_7_days' ? 7 : 30;
        $breakdown = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dateKey = $date->format('Y-m-d');

            $count = $submissions->filter(function ($submission) use ($date) {
                return $submission->date()->format('Y-m-d') === $date->format('Y-m-d');
            })->count();

            $breakdown[$dateKey] = $count;
        }

        return $breakdown;
    }

    /**
     * Get field-level statistics.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $submissions
     *
     * @return array<string, array<string, mixed>>
     */
    private function getFieldStatistics($submissions): array
    {
        $allData = $submissions->map(fn ($s) => $s->data()->all())->all();
        $fieldStats = [];

        // Get all unique field names
        $allFields = collect($allData)->reduce(function ($carry, $data) {
            return array_merge($carry, array_keys($data));
        }, []);

        $uniqueFields = array_unique($allFields);

        foreach ($uniqueFields as $field) {
            $values = collect($allData)
                ->pluck($field)
                ->filter()
                ->values();

            $fieldStats[$field] = [
                'total_responses' => $values->count(),
                'response_rate' => round(($values->count() / $submissions->count()) * 100, 2),
                'unique_values' => $values->unique()->count(),
                'most_common' => $this->getMostCommonValue($values),
            ];

            // Add numeric statistics for numeric fields
            if ($values->every(fn ($v) => is_numeric($v))) {
                $average = $values->average();
                $fieldStats[$field]['numeric_stats'] = [
                    'average' => $average !== null ? round($average, 2) : null,
                    'min' => $values->min(),
                    'max' => $values->max(),
                ];
            }
        }

        return $fieldStats;
    }

    /**
     * Get hourly distribution of submissions.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $submissions
     *
     * @return array<string, int>
     */
    private function getHourlyDistribution($submissions): array
    {
        $hourly = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $count = $submissions->filter(function ($submission) use ($hour) {
                return (int) $submission->date()->format('H') === $hour;
            })->count();

            $hourly[sprintf('%02d:00', $hour)] = $count;
        }

        return $hourly;
    }

    /**
     * Get most common value for a field.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $values
     */
    private function getMostCommonValue($values): int|string|null
    {
        if ($values->isEmpty()) {
            return null;
        }

        return $values->groupBy(fn ($v) => $v)
            ->sortByDesc(fn ($group) => $group->count())
            ->keys()
            ->first();
    }
}
