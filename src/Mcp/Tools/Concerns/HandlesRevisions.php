<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\Entry;
use Statamic\Revisions\Revision;

/**
 * Handles Statamic revision workflows for entries.
 *
 * Encapsulates revision-aware save, list, get, and restore operations
 * matching the Statamic CP's exact editorial workflow.
 */
trait HandlesRevisions
{
    /**
     * Check if revisions are enabled for the given entry.
     *
     * Wraps the Revisable trait's method in a try/catch for graceful
     * fallback when Statamic Pro is not licensed.
     */
    private function entryRevisionsEnabled(EntryContract $entry): bool
    {
        try {
            /** @var Entry $entry */
            return $entry->revisionsEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Guard clause: returns error response if revisions are not enabled.
     *
     * @return array<string, mixed>|null Error response or null if OK
     */
    private function requireRevisionsEnabled(EntryContract $entry): ?array
    {
        if (! $this->entryRevisionsEnabled($entry)) {
            return $this->createErrorResponse(
                'Revisions are not enabled for this entry. Ensure Statamic Pro is licensed and revisions are enabled for this collection.'
            )->toArray();
        }

        return null;
    }

    /**
     * Save entry data as a working copy (revision-aware update for published entries).
     *
     * The entry already has its data set from the normal update pipeline.
     * This creates/updates a working copy matching the CP behavior.
     *
     * @param  array<string, mixed>  $processedData
     *
     * @return array<string, mixed>
     */
    private function saveAsWorkingCopy(EntryContract $entry, array $processedData, ?string $message): array
    {
        /** @var Entry $entry */

        // Preserve original data so we don't mutate the published entry in memory
        $originalData = $entry->data()->all();

        // Merge processed data onto the entry so makeWorkingCopy() captures the new state
        $entry->merge($processedData);

        // Create the working copy (captures revisionAttributes from entry's current state)
        $workingCopy = $entry->makeWorkingCopy();

        if ($message !== null && $message !== '') {
            $workingCopy->message($message);
        }

        $workingCopy->save();

        // Restore original data on the in-memory entry to prevent stache contamination
        $entry->data($originalData);

        return [
            'entry' => [
                'id' => $entry->id(),
                'slug' => $entry->slug(),
                'collection' => $entry->collectionHandle(),
                'site' => $entry->site()->handle(),
                'published' => $entry->published(),
                'last_modified' => $entry->lastModified()?->toISOString(),
                'url' => $entry->url(),
            ],
            'updated' => true,
            'working_copy' => true,
            'revision_status' => $this->getRevisionStatusMeta($entry),
        ];
    }

    /**
     * List revisions for an entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listRevisionsAction(array $arguments): array
    {
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : '';

        try {
            $entry = \Statamic\Facades\Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            /** @var Entry $entry */
            $revisionsError = $this->requireRevisionsEnabled($entry);
            if ($revisionsError) {
                return $revisionsError;
            }

            $revisions = $entry->revisions();

            $formatted = $revisions->map(function (Revision $revision) {
                return $this->formatRevisionMeta($revision);
            })->values()->all();

            return [
                'entry_id' => $entry->id(),
                'revisions' => $formatted,
                'total' => count($formatted),
                'revision_status' => $this->getRevisionStatusMeta($entry),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list revisions: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Get a specific revision by timestamp ID.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getRevisionAction(array $arguments): array
    {
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : '';
        $revisionId = is_string($arguments['revision_id'] ?? null) ? $arguments['revision_id'] : '';

        try {
            $entry = \Statamic\Facades\Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            /** @var Entry $entry */
            $revisionsError = $this->requireRevisionsEnabled($entry);
            if ($revisionsError) {
                return $revisionsError;
            }

            $revision = $entry->revision($revisionId);

            if ($revision === null) {
                return $this->createErrorResponse("Revision not found: {$revisionId}")->toArray();
            }

            return [
                'entry_id' => $entry->id(),
                'revision' => [
                    ...$this->formatRevisionMeta($revision),
                    'attributes' => $revision->attributes(),
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get revision: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Restore an entry from a specific revision.
     *
     * Mirrors the CP's RestoreEntryRevisionController:
     * - Published entries: revision becomes a working copy
     * - Unpublished entries: entry is updated directly from revision
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function restoreRevisionAction(array $arguments): array
    {
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : '';
        $revisionId = is_string($arguments['revision_id'] ?? null) ? $arguments['revision_id'] : '';

        try {
            $entry = \Statamic\Facades\Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            /** @var Entry $entry */
            $revisionsError = $this->requireRevisionsEnabled($entry);
            if ($revisionsError) {
                return $revisionsError;
            }

            $revision = $entry->revision($revisionId);

            if ($revision === null) {
                return $this->createErrorResponse("Revision not found: {$revisionId}")->toArray();
            }

            if ($entry->published()) {
                // Published: create a working copy from the revision
                $revision->toWorkingCopy()->date(now())->save();
            } else {
                // Unpublished: directly update the entry from the revision
                $restoredEntry = $entry->makeFromRevision($revision);
                $restoredEntry->published(false)->save();
            }

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry_id' => $entry->id(),
                'restored_from' => $revisionId,
                'restored_as_working_copy' => $entry->published(),
                'revision_status' => $this->getRevisionStatusMeta($entry),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to restore revision: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Publish the current working copy.
     *
     * Uses Statamic's built-in publish() which delegates to publishWorkingCopy()
     * when revisions are enabled.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function publishWorkingCopyAction(array $arguments): array
    {
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : '';

        try {
            $entry = \Statamic\Facades\Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            /** @var Entry $entry */
            $revisionsError = $this->requireRevisionsEnabled($entry);
            if ($revisionsError) {
                return $revisionsError;
            }

            if (! $entry->hasWorkingCopy()) {
                return $this->createErrorResponse('No working copy exists to publish')->toArray();
            }

            $options = array_filter([
                'message' => $arguments['revision_message'] ?? null,
            ]);

            $entry->publish($options);

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            // Re-fetch entry for fresh state
            $refreshed = \Statamic\Facades\Entry::find($id);

            if ($refreshed === null) {
                return $this->createErrorResponse('Failed to re-fetch entry after publishing')->toArray();
            }

            return [
                'entry' => [
                    'id' => $refreshed->id(),
                    'slug' => $refreshed->slug(),
                    'collection' => $refreshed->collectionHandle(),
                    'site' => $refreshed->site()->handle(),
                    'published' => $refreshed->published(),
                    'url' => $refreshed->url(),
                ],
                'published' => true,
                'revision_status' => $this->getRevisionStatusMeta($refreshed),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to publish working copy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Format a revision for list output (metadata only, no full data snapshot).
     *
     * @return array<string, mixed>
     */
    private function formatRevisionMeta(Revision $revision): array
    {
        return [
            'id' => (string) $revision->date()->timestamp,
            'date' => $revision->date()->toISOString(),
            'user' => $revision->user()?->id(),
            'message' => $revision->message(),
            'action' => $revision->action(),
        ];
    }

    /**
     * Get revision status metadata for an entry.
     *
     * @return array<string, mixed>
     */
    private function getRevisionStatusMeta(EntryContract $entry): array
    {
        /** @var Entry $entry */
        if (! $this->entryRevisionsEnabled($entry)) {
            return [];
        }

        $meta = [
            'revisions_enabled' => true,
            'has_working_copy' => $entry->hasWorkingCopy(),
        ];

        if ($entry->hasWorkingCopy()) {
            $workingCopy = $entry->workingCopy();
            $meta['working_copy_date'] = $workingCopy?->date()?->toISOString();
        }

        return $meta;
    }
}
