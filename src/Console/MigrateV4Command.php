<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Auth\V4TokenMigration;
use Illuminate\Console\Command;

/** Shows, and with --apply carries out, the token changes an upgrade to v4 (or a rollback to v3) needs. */
final class MigrateV4Command extends Command
{
    protected $signature = 'mcp:migrate-v4
        {--apply : Carry out the plan; without it nothing is changed}
        {--rollback : Plan the revocations needed before downgrading to v3}
        {--json : Print the plan and result as JSON}';

    protected $description = 'Plan (dry run by default) and apply the token changes for upgrading to v4 or rolling back to v3';

    public function handle(V4TokenMigration $migration): int
    {
        $mode = $this->option('rollback') ? V4TokenMigration::ROLLBACK : V4TokenMigration::UPGRADE;
        $apply = (bool) $this->option('apply');

        try {
            $steps = $migration->plan($mode);
        } catch (\Throwable) {
            return $this->finish(['error' => 'The token store could not be read; nothing was changed. Details are omitted to protect credentials.'], self::FAILURE);
        }

        $report = ['schema' => 1, 'mode' => $mode, 'dry_run' => ! $apply, 'steps' => $steps];

        if (! $this->option('json')) {
            $this->info($mode === V4TokenMigration::ROLLBACK ? 'Tokens to revoke before downgrading to v3' : 'Token changes for v4');
            if ($steps === []) {
                $this->line('Nothing to do.');
            } else {
                $this->table(['Action', 'Token', 'Owner', 'Why'], array_map(static fn (array $step): array => [
                    $step['action'] === 'narrow' ? 'narrow → ' . implode(', ', $step['after'] ?? []) : $step['action'],
                    $step['name'] . ($step['oauth_client_id'] !== null ? ' (OAuth)' : '') . " [{$step['token_id']}]",
                    $step['owner'],
                    $step['reason'],
                ], $steps));
            }
        }

        $changing = array_filter($steps, static fn (array $step): bool => $step['action'] !== 'keep');
        if (! $apply || $changing === []) {
            if (! $this->option('json') && $changing !== []) {
                $this->line('Dry run: nothing was changed. Run again with --apply to carry out the revoke and narrow steps.');
            }

            return $this->finish($report, self::SUCCESS);
        }

        try {
            $result = $migration->apply($mode, $steps);
        } catch (\Throwable) {
            return $this->finish($report + ['error' => 'The migration journal could not be written, so nothing was changed. Check the storage path.'], self::FAILURE);
        }

        $report += $result;
        if (! $this->option('json')) {
            $this->line("Journal: {$result['journal']}");
            $result['error'] === null
                ? $this->info("Applied {$result['applied']} step(s).")
                : $this->error($result['error']);
        }

        return $this->finish($report, $result['error'] === null ? self::SUCCESS : self::FAILURE);
    }

    /** @param  array<string, mixed>  $report */
    private function finish(array $report, int $code): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (isset($report['error']) && ! isset($report['journal'])) {
            $this->error(is_string($report['error']) ? $report['error'] : 'Failed.');
        }

        return $code;
    }
}
