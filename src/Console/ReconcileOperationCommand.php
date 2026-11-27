<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Actions\Operations\OperationRecovery;
use Illuminate\Console\Command;

final class ReconcileOperationCommand extends Command
{
    protected $signature = 'mcp:reconcile-operation {id : Retained operation handle}
        {--expected-version= : Version returned by inspect-operation}
        {--reported-by= : Operator identity recorded with the report}
        {--outcome= : Investigated outcome: no_effect, committed, or partial}
        {--evidence-file= : Local UTF-8 text file containing investigation evidence, at most 12000 bytes}
        {--apply : Persist the report and close the handle; default is read-only}';

    protected $description = 'Record an investigated unknown outcome without retrying content writes';

    public function handle(OperationRecovery $recovery): int
    {
        $id = $this->argument('id');
        $version = $this->option('expected-version');
        $operator = $this->option('reported-by');
        $outcome = $this->option('outcome');
        $path = $this->option('evidence-file');
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{64}$/D', $id)
            || ! is_string($version) || ! preg_match('/^[1-9][0-9]{0,8}$/D', $version)
            || ! is_string($operator) || trim($operator) === '' || strlen($operator) > 255
            || ! in_array($outcome, ['no_effect', 'committed', 'partial'], true)
            || ! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            $this->error('Provide a handle, inspected version, operator, outcome and readable evidence file.');

            return self::INVALID;
        }
        try {
            $evidence = file_get_contents($path, length: 12001);
            if (! is_string($evidence) || trim($evidence) === '' || strlen($evidence) > 12000 || ! mb_check_encoding($evidence, 'UTF-8')) {
                $this->error('Evidence must contain 1–12000 bytes of UTF-8 text.');

                return self::INVALID;
            }
            $recovery->inspect($id, (int) $version);
            if (! $this->option('apply')) {
                $this->info('Dry run: the unknown outcome can be closed with this report. No state changed.');
                $this->line('Verify all content, cache and external effects before using --apply. The report is an operator assertion.');

                return self::SUCCESS;
            }
            $recovery->reconcile($id, (int) $version, $operator, $outcome, $evidence);
            $this->info('Report retained for at least 30 days. The handle is closed and cannot execute again. No content was changed.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Reconciliation was not acknowledged. Inspect the operation before retrying; no handler was invoked.');

            return self::FAILURE;
        }
    }
}
