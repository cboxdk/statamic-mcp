<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Audit;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Tests\Concerns\AuditStoreContractTests;
use PHPUnit\Framework\TestCase;

class FileAuditStoreTest extends TestCase
{
    use AuditStoreContractTests;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir() . '/statamic-mcp-audit-test-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }

        parent::tearDown();
    }

    protected function createStore(): AuditStore
    {
        return new FileAuditStore($this->tempPath);
    }
}
