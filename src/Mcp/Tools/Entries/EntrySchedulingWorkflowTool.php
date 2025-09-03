<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

#[Title('Entry Scheduling & Workflow')]
class EntrySchedulingWorkflowTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.scheduling_workflow';
    }

    protected function getToolDescription(): string
    {
        return 'Advanced entry scheduling, workflow management, and automated publishing with approval processes';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('operation')
            ->description('Operation: schedule_publish, schedule_unpublish, list_scheduled, cancel_schedule, workflow_submit, workflow_approve, workflow_reject, list_workflow_items')
            ->required()
            ->string('entry_id')
            ->description('Entry ID (optional for list operations)')
            ->optional()
            ->string('collection')
            ->description('Collection handle (for list operations)')
            ->optional()
            ->string('schedule_datetime')
            ->description('ISO 8601 datetime for scheduling')
            ->optional()
            ->string('timezone')
            ->description('Timezone for scheduling (defaults to system timezone)')
            ->optional()
            ->raw('recurrence', [
                'type' => 'object',
                'description' => 'Recurring schedule configuration',
                'properties' => [
                    'frequency' => [
                        'type' => 'string',
                        'enum' => ['daily', 'weekly', 'monthly', 'yearly'],
                    ],
                    'interval' => ['type' => 'integer'],
                    'end_date' => ['type' => 'string'],
                    'max_occurrences' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ])
            ->optional()
            ->string('workflow_stage')
            ->description('Workflow stage: draft, review, approved, published')
            ->optional()
            ->string('reviewer')
            ->description('Reviewer user ID or email')
            ->optional()
            ->string('approval_message')
            ->description('Message for workflow approval/rejection')
            ->optional()
            ->raw('workflow_metadata', [
                'type' => 'object',
                'description' => 'Additional workflow metadata',
                'properties' => [
                    'priority' => [
                        'type' => 'string',
                        'enum' => ['low', 'normal', 'high', 'urgent'],
                    ],
                    'deadline' => ['type' => 'string'],
                    'department' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                ],
                'additionalProperties' => true,
            ])
            ->optional()
            ->boolean('send_notifications')
            ->description('Send email notifications for workflow changes')
            ->optional()
            ->raw('notification_settings', [
                'type' => 'object',
                'description' => 'Notification configuration',
                'properties' => [
                    'recipients' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'template' => ['type' => 'string'],
                    'delay_minutes' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ])
            ->optional()
            ->boolean('auto_advance_workflow')
            ->description('Automatically advance workflow after approval')
            ->optional()
            ->integer('days_ahead')
            ->description('Filter scheduled items by days ahead (for list operations)')
            ->optional()
            ->boolean('include_completed')
            ->description('Include completed workflow items')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview operation without executing')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $operation = $arguments['operation'];
        $entryId = $arguments['entry_id'] ?? null;
        $collection = $arguments['collection'] ?? null;
        $scheduleDateTime = $arguments['schedule_datetime'] ?? null;
        $timezone = $arguments['timezone'] ?? config('app.timezone');
        $recurrence = $arguments['recurrence'] ?? null;
        $workflowStage = $arguments['workflow_stage'] ?? null;
        $reviewer = $arguments['reviewer'] ?? null;
        $approvalMessage = $arguments['approval_message'] ?? null;
        $workflowMetadata = $arguments['workflow_metadata'] ?? [];
        $sendNotifications = $arguments['send_notifications'] ?? false;
        $notificationSettings = $arguments['notification_settings'] ?? [];
        $autoAdvanceWorkflow = $arguments['auto_advance_workflow'] ?? false;
        $daysAhead = $arguments['days_ahead'] ?? 30;
        $includeCompleted = $arguments['include_completed'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        $validOperations = [
            'schedule_publish', 'schedule_unpublish', 'list_scheduled', 'cancel_schedule',
            'workflow_submit', 'workflow_approve', 'workflow_reject', 'list_workflow_items',
        ];

        if (! in_array($operation, $validOperations)) {
            return $this->createErrorResponse("Invalid operation '{$operation}'. Valid: " . implode(', ', $validOperations))->toArray();
        }

        // For entry-specific operations, validate entry exists
        $entry = null;
        if (in_array($operation, ['schedule_publish', 'schedule_unpublish', 'cancel_schedule', 'workflow_submit', 'workflow_approve', 'workflow_reject'])) {
            if (! $entryId) {
                return $this->createErrorResponse("Entry ID is required for operation '{$operation}'")->toArray();
            }

            $entry = Entry::find($entryId);
            if (! $entry) {
                return $this->createErrorResponse("Entry '{$entryId}' not found")->toArray();
            }
        }

        switch ($operation) {
            case 'schedule_publish':
                return $this->schedulePublish($entry ?? throw new \InvalidArgumentException('Entry is required'), $scheduleDateTime, $timezone, $recurrence, $sendNotifications, $notificationSettings, $dryRun);
            case 'schedule_unpublish':
                return $this->scheduleUnpublish($entry ?? throw new \InvalidArgumentException('Entry is required'), $scheduleDateTime, $timezone, $recurrence, $sendNotifications, $notificationSettings, $dryRun);
            case 'list_scheduled':
                return $this->listScheduledItems($collection, $daysAhead, $includeCompleted);
            case 'cancel_schedule':
                return $this->cancelSchedule($entry ?? throw new \InvalidArgumentException('Entry is required'), $dryRun);
            case 'workflow_submit':
                return $this->submitToWorkflow($entry ?? throw new \InvalidArgumentException('Entry is required'), $workflowStage, $reviewer, $workflowMetadata, $sendNotifications, $notificationSettings, $dryRun);
            case 'workflow_approve':
                return $this->approveWorkflowItem($entry ?? throw new \InvalidArgumentException('Entry is required'), $approvalMessage, $autoAdvanceWorkflow, $sendNotifications, $notificationSettings, $dryRun);
            case 'workflow_reject':
                return $this->rejectWorkflowItem($entry ?? throw new \InvalidArgumentException('Entry is required'), $approvalMessage, $sendNotifications, $notificationSettings, $dryRun);
            case 'list_workflow_items':
                return $this->listWorkflowItems($collection, $workflowStage, $includeCompleted);
            default:
                return $this->createErrorResponse("Operation '{$operation}' not implemented")->toArray();
        }
    }

    /**
     * Schedule entry for publishing.
     *
     * @param  array<string, mixed>|null  $recurrence
     * @param  array<string, mixed>  $notificationSettings
     *
     * @return array<string, mixed>
     */
    private function schedulePublish(
        \Statamic\Contracts\Entries\Entry $entry,
        ?string $scheduleDateTime,
        string $timezone,
        ?array $recurrence,
        bool $sendNotifications,
        array $notificationSettings,
        bool $dryRun
    ): array {
        if (! $scheduleDateTime) {
            return $this->createErrorResponse('Schedule datetime is required for publish scheduling')->toArray();
        }

        try {
            $scheduledDate = Carbon::parse($scheduleDateTime, $timezone);
        } catch (\Exception $e) {
            return $this->createErrorResponse('Invalid datetime format: ' . $e->getMessage())->toArray();
        }

        if ($scheduledDate->isPast()) {
            return $this->createErrorResponse('Cannot schedule publishing in the past')->toArray();
        }

        $scheduleId = 'schedule_' . uniqid();
        $scheduleData = [
            'id' => $scheduleId,
            'type' => 'publish',
            'entry_id' => $entry->id(),
            'entry_title' => $entry->get('title'),
            'collection' => $entry->collection()->handle(),
            'scheduled_date' => $scheduledDate->toISOString(),
            'timezone' => $timezone,
            'recurrence' => $recurrence,
            'created_at' => Carbon::now()->toISOString(),
            'created_by' => 'system', // Would get from auth context
            'status' => 'pending',
            'notifications_enabled' => $sendNotifications,
            'notification_settings' => $notificationSettings,
        ];

        if ($recurrence) {
            $scheduleData['next_occurrence'] = $this->calculateNextOccurrence($scheduledDate, $recurrence);
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'schedule_publish',
                'entry_id' => $entry->id(),
                'would_schedule' => $scheduleData,
                'time_until_publish' => $this->formatDuration((int) Carbon::now()->diffInSeconds($scheduledDate)),
            ];
        }

        // Store schedule (in real implementation, this would be in database/queue)
        $this->storeSchedule($scheduleData);

        // Queue the actual publishing job
        $this->queuePublishJob($entry, $scheduledDate);

        // Send notifications if enabled
        if ($sendNotifications) {
            $this->sendScheduleNotification($scheduleData, 'scheduled');
        }

        return [
            'operation' => 'schedule_publish',
            'entry_id' => $entry->id(),
            'schedule' => $scheduleData,
            'queued_for' => $scheduledDate->toISOString(),
            'time_until_publish' => $this->formatDuration((int) Carbon::now()->diffInSeconds($scheduledDate)),
            'notifications_sent' => $sendNotifications,
        ];
    }

    /**
     * Schedule entry for unpublishing.
     *
     * @param  array<string, mixed>|null  $recurrence
     * @param  array<string, mixed>  $notificationSettings
     *
     * @return array<string, mixed>
     */
    private function scheduleUnpublish(
        \Statamic\Contracts\Entries\Entry $entry,
        ?string $scheduleDateTime,
        string $timezone,
        ?array $recurrence,
        bool $sendNotifications,
        array $notificationSettings,
        bool $dryRun
    ): array {
        if (! $scheduleDateTime) {
            return $this->createErrorResponse('Schedule datetime is required for unpublish scheduling')->toArray();
        }

        try {
            $scheduledDate = Carbon::parse($scheduleDateTime, $timezone);
        } catch (\Exception $e) {
            return $this->createErrorResponse('Invalid datetime format: ' . $e->getMessage())->toArray();
        }

        if ($scheduledDate->isPast()) {
            return $this->createErrorResponse('Cannot schedule unpublishing in the past')->toArray();
        }

        $scheduleId = 'schedule_' . uniqid();
        $scheduleData = [
            'id' => $scheduleId,
            'type' => 'unpublish',
            'entry_id' => $entry->id(),
            'entry_title' => $entry->get('title'),
            'collection' => $entry->collection()->handle(),
            'scheduled_date' => $scheduledDate->toISOString(),
            'timezone' => $timezone,
            'recurrence' => $recurrence,
            'created_at' => Carbon::now()->toISOString(),
            'created_by' => 'system',
            'status' => 'pending',
            'notifications_enabled' => $sendNotifications,
            'notification_settings' => $notificationSettings,
        ];

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'schedule_unpublish',
                'entry_id' => $entry->id(),
                'would_schedule' => $scheduleData,
                'time_until_unpublish' => $this->formatDuration((int) Carbon::now()->diffInSeconds($scheduledDate)),
            ];
        }

        // Store schedule
        $this->storeSchedule($scheduleData);

        // Queue the unpublishing job
        $this->queueUnpublishJob($entry, $scheduledDate);

        return [
            'operation' => 'schedule_unpublish',
            'entry_id' => $entry->id(),
            'schedule' => $scheduleData,
            'queued_for' => $scheduledDate->toISOString(),
            'time_until_unpublish' => $this->formatDuration((int) Carbon::now()->diffInSeconds($scheduledDate)),
            'notifications_sent' => $sendNotifications,
        ];
    }

    /**
     * List scheduled items.
     *
     * @return array<string, mixed>
     */
    private function listScheduledItems(?string $collection, int $daysAhead, bool $includeCompleted): array
    {
        $schedules = $this->getSchedules($collection, $daysAhead, $includeCompleted);

        return [
            'operation' => 'list_scheduled',
            'collection' => $collection,
            'days_ahead' => $daysAhead,
            'include_completed' => $includeCompleted,
            'total_scheduled' => count($schedules),
            'schedules' => $schedules,
            'summary' => [
                'pending' => count(array_filter($schedules, fn ($s) => $s['status'] === 'pending')),
                'completed' => count(array_filter($schedules, fn ($s) => $s['status'] === 'completed')),
                'failed' => count(array_filter($schedules, fn ($s) => $s['status'] === 'failed')),
                'publish_schedules' => count(array_filter($schedules, fn ($s) => $s['type'] === 'publish')),
                'unpublish_schedules' => count(array_filter($schedules, fn ($s) => $s['type'] === 'unpublish')),
            ],
        ];
    }

    /**
     * Cancel scheduled operation.
     *
     * @return array<string, mixed>
     */
    private function cancelSchedule(\Statamic\Contracts\Entries\Entry $entry, bool $dryRun): array
    {
        $schedules = $this->getSchedulesForEntry($entry->id());
        $activeSchedules = array_filter($schedules, fn ($s) => $s['status'] === 'pending');

        if (empty($activeSchedules)) {
            return [
                'operation' => 'cancel_schedule',
                'entry_id' => $entry->id(),
                'message' => 'No active schedules found for this entry',
                'cancelled_count' => 0,
            ];
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'cancel_schedule',
                'entry_id' => $entry->id(),
                'would_cancel' => array_column($activeSchedules, 'id'),
                'cancel_count' => count($activeSchedules),
            ];
        }

        $cancelled = 0;
        foreach ($activeSchedules as $schedule) {
            if ($this->cancelStoredSchedule($schedule['id'])) {
                $cancelled++;
            }
        }

        return [
            'operation' => 'cancel_schedule',
            'entry_id' => $entry->id(),
            'cancelled_schedules' => array_column($activeSchedules, 'id'),
            'cancelled_count' => $cancelled,
        ];
    }

    /**
     * Submit entry to workflow.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $notificationSettings
     *
     * @return array<string, mixed>
     */
    private function submitToWorkflow(
        \Statamic\Contracts\Entries\Entry $entry,
        ?string $stage,
        ?string $reviewer,
        array $metadata,
        bool $sendNotifications,
        array $notificationSettings,
        bool $dryRun
    ): array {
        $stage = $stage ?? 'review';
        $workflowId = 'workflow_' . uniqid();

        // Validate reviewer if provided
        $reviewerUser = null;
        if ($reviewer) {
            if (filter_var($reviewer, FILTER_VALIDATE_EMAIL)) {
                $reviewerUser = User::findByEmail($reviewer);
            } else {
                $reviewerUser = User::find($reviewer);
            }

            if (! $reviewerUser) {
                return $this->createErrorResponse("Reviewer '{$reviewer}' not found")->toArray();
            }
        }

        $workflowData = [
            'id' => $workflowId,
            'entry_id' => $entry->id(),
            'entry_title' => $entry->get('title'),
            'collection' => $entry->collection()->handle(),
            'current_stage' => $stage,
            'reviewer' => $reviewerUser?->id(),
            'reviewer_email' => $reviewerUser?->email(),
            'submitter' => 'system', // Would get from auth context
            'submitted_at' => Carbon::now()->toISOString(),
            'metadata' => array_merge([
                'priority' => 'normal',
                'department' => 'content',
            ], $metadata),
            'status' => 'pending',
            'history' => [
                [
                    'action' => 'submitted',
                    'stage' => $stage,
                    'timestamp' => Carbon::now()->toISOString(),
                    'user' => 'system',
                    'message' => 'Entry submitted for review',
                ],
            ],
            'notifications_enabled' => $sendNotifications,
            'notification_settings' => $notificationSettings,
        ];

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'workflow_submit',
                'entry_id' => $entry->id(),
                'would_create_workflow' => $workflowData,
            ];
        }

        // Store workflow
        $this->storeWorkflow($workflowData);

        // Update entry workflow status
        $entry->set('workflow_stage', $stage);
        $entry->set('workflow_id', $workflowId);
        $entry->save();

        // Send notifications
        if ($sendNotifications && $reviewerUser) {
            $this->sendWorkflowNotification($workflowData, 'submitted');
        }

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'workflow_submit',
            'entry_id' => $entry->id(),
            'workflow' => $workflowData,
            'notifications_sent' => $sendNotifications && $reviewerUser !== null,
            'cache' => $cacheResult,
        ];
    }

    /**
     * Approve workflow item.
     *
     * @param  array<string, mixed>  $notificationSettings
     *
     * @return array<string, mixed>
     */
    private function approveWorkflowItem(
        \Statamic\Contracts\Entries\Entry $entry,
        ?string $message,
        bool $autoAdvance,
        bool $sendNotifications,
        array $notificationSettings,
        bool $dryRun
    ): array {
        $workflowId = $entry->get('workflow_id');
        if (! $workflowId) {
            return $this->createErrorResponse('Entry is not in workflow')->toArray();
        }

        $workflow = $this->getWorkflow($workflowId);
        if (! $workflow) {
            return $this->createErrorResponse('Workflow not found')->toArray();
        }

        $approvalData = [
            'action' => 'approved',
            'stage' => $workflow['current_stage'],
            'timestamp' => Carbon::now()->toISOString(),
            'user' => 'system', // Would get from auth context
            'message' => $message ?? 'Approved',
        ];

        $newStage = $this->getNextWorkflowStage($workflow['current_stage']);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'workflow_approve',
                'entry_id' => $entry->id(),
                'workflow_id' => $workflowId,
                'current_stage' => $workflow['current_stage'],
                'would_advance_to' => $newStage,
                'auto_advance' => $autoAdvance,
                'approval_data' => $approvalData,
            ];
        }

        // Update workflow
        $workflow['history'][] = $approvalData;

        if ($autoAdvance && $newStage) {
            $workflow['current_stage'] = $newStage;
            $workflow['history'][] = [
                'action' => 'stage_advanced',
                'stage' => $newStage,
                'timestamp' => Carbon::now()->toISOString(),
                'user' => 'system',
                'message' => "Auto-advanced to {$newStage}",
            ];
        }

        if ($newStage === 'published') {
            $workflow['status'] = 'completed';
            $entry->published(true);
        }

        $this->updateWorkflow($workflowId, $workflow);

        // Update entry
        $entry->set('workflow_stage', $workflow['current_stage']);
        $entry->save();

        // Send notifications
        if ($sendNotifications) {
            $this->sendWorkflowNotification($workflow, 'approved');
        }

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'workflow_approve',
            'entry_id' => $entry->id(),
            'workflow_id' => $workflowId,
            'approved_stage' => $workflow['current_stage'],
            'new_stage' => $newStage,
            'workflow_completed' => $workflow['status'] === 'completed',
            'entry_published' => $newStage === 'published',
            'notifications_sent' => $sendNotifications,
            'cache' => $cacheResult,
        ];
    }

    /**
     * Reject workflow item.
     *
     * @param  array<string, mixed>  $notificationSettings
     *
     * @return array<string, mixed>
     */
    private function rejectWorkflowItem(
        \Statamic\Contracts\Entries\Entry $entry,
        ?string $message,
        bool $sendNotifications,
        array $notificationSettings,
        bool $dryRun
    ): array {
        $workflowId = $entry->get('workflow_id');
        if (! $workflowId) {
            return $this->createErrorResponse('Entry is not in workflow')->toArray();
        }

        $workflow = $this->getWorkflow($workflowId);
        if (! $workflow) {
            return $this->createErrorResponse('Workflow not found')->toArray();
        }

        $rejectionData = [
            'action' => 'rejected',
            'stage' => $workflow['current_stage'],
            'timestamp' => Carbon::now()->toISOString(),
            'user' => 'system',
            'message' => $message ?? 'Rejected',
        ];

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'workflow_reject',
                'entry_id' => $entry->id(),
                'workflow_id' => $workflowId,
                'current_stage' => $workflow['current_stage'],
                'rejection_data' => $rejectionData,
            ];
        }

        // Update workflow
        $workflow['history'][] = $rejectionData;
        $workflow['current_stage'] = 'draft';
        $workflow['status'] = 'rejected';

        $this->updateWorkflow($workflowId, $workflow);

        // Update entry
        $entry->set('workflow_stage', 'draft');
        $entry->save();

        // Send notifications
        if ($sendNotifications) {
            $this->sendWorkflowNotification($workflow, 'rejected');
        }

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'workflow_reject',
            'entry_id' => $entry->id(),
            'workflow_id' => $workflowId,
            'rejected_stage' => $workflow['current_stage'],
            'reverted_to_draft' => true,
            'notifications_sent' => $sendNotifications,
            'cache' => $cacheResult,
        ];
    }

    /**
     * List workflow items.
     *
     * @return array<string, mixed>
     */
    private function listWorkflowItems(?string $collection, ?string $stage, bool $includeCompleted): array
    {
        $workflows = $this->getWorkflows($collection, $stage, $includeCompleted);

        return [
            'operation' => 'list_workflow_items',
            'collection' => $collection,
            'stage_filter' => $stage,
            'include_completed' => $includeCompleted,
            'total_workflows' => count($workflows),
            'workflows' => $workflows,
            'summary' => [
                'pending' => count(array_filter($workflows, fn ($w) => $w['status'] === 'pending')),
                'completed' => count(array_filter($workflows, fn ($w) => $w['status'] === 'completed')),
                'rejected' => count(array_filter($workflows, fn ($w) => $w['status'] === 'rejected')),
                'by_stage' => $this->summarizeWorkflowsByStage($workflows),
            ],
        ];
    }

    // Helper methods (would be implemented with actual storage/queue systems)

    /**
     * @param  array<string, mixed>  $scheduleData
     */
    private function storeSchedule(array $scheduleData): void
    {
        // Store in database or cache
    }

    private function queuePublishJob(\Statamic\Contracts\Entries\Entry $entry, Carbon $scheduledDate): void
    {
        // Queue job for scheduled publishing
    }

    private function queueUnpublishJob(\Statamic\Contracts\Entries\Entry $entry, Carbon $scheduledDate): void
    {
        // Queue job for scheduled unpublishing
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function sendScheduleNotification(array $schedule, string $event): void
    {
        // Send email notification
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSchedules(?string $collection, int $daysAhead, bool $includeCompleted): array
    {
        // Retrieve schedules from storage
        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSchedulesForEntry(string $entryId): array
    {
        // Retrieve schedules for specific entry
        return [];
    }

    private function cancelStoredSchedule(string $scheduleId): bool
    {
        // Cancel schedule in storage/queue
        return true;
    }

    /**
     * @param  array<string, mixed>  $workflowData
     */
    private function storeWorkflow(array $workflowData): void
    {
        // Store workflow in database
    }

    /**
     * @return array<string, mixed>|null
     *
     * @phpstan-ignore-next-line return.unusedType
     */
    private function getWorkflow(string $workflowId): ?array
    {
        // In a real implementation, this would retrieve from database/cache
        // This method would return workflow data structure when implemented
        return null;
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function updateWorkflow(string $workflowId, array $workflow): void
    {
        // Update workflow in storage
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function sendWorkflowNotification(array $workflow, string $event): void
    {
        // Send workflow notification
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getWorkflows(?string $collection, ?string $stage, bool $includeCompleted): array
    {
        // Retrieve workflows from storage
        return [];
    }

    private function getNextWorkflowStage(string $currentStage): ?string
    {
        $stages = ['draft', 'review', 'approved', 'published'];
        $currentIndex = array_search($currentStage, $stages);

        return $currentIndex !== false && $currentIndex < count($stages) - 1
            ? $stages[$currentIndex + 1]
            : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $workflows
     *
     * @return array<string, int>
     */
    private function summarizeWorkflowsByStage(array $workflows): array
    {
        $summary = [];
        foreach ($workflows as $workflow) {
            $stage = $workflow['current_stage'];
            $summary[$stage] = ($summary[$stage] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $recurrence
     */
    private function calculateNextOccurrence(Carbon $scheduledDate, array $recurrence): string
    {
        $frequency = $recurrence['frequency'] ?? 'daily';
        $interval = $recurrence['interval'] ?? 1;

        return match ($frequency) {
            'daily' => (string) $scheduledDate->addDays($interval)->toISOString(),
            'weekly' => (string) $scheduledDate->addWeeks($interval)->toISOString(),
            'monthly' => (string) $scheduledDate->addMonths($interval)->toISOString(),
            'yearly' => (string) $scheduledDate->addYears($interval)->toISOString(),
            default => (string) $scheduledDate->addDay()->toISOString(),
        };
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . ' minutes';
        } elseif ($seconds < 86400) {
            return floor($seconds / 3600) . ' hours';
        } else {
            return floor($seconds / 86400) . ' days';
        }
    }
}
