<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

class ResponseSizeLimitTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(4));
        Collection::make($this->collectionHandle)->title('Pages')->save();

        Blueprint::make('pages')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'body', 'field' => ['type' => 'textarea']],
            ],
        ])->save();

        Entry::make()
            ->id('big')
            ->collection($this->collectionHandle)
            ->slug('big')
            ->data(['title' => 'Big', 'body' => str_repeat('a', 20000)])
            ->save();

        Stache::refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        return $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => 'big',
        ]);
    }

    public function test_the_default_limit_is_unchanged(): void
    {
        $this->assertSame(100000, config('statamic.mcp.security.max_response_size'));
        $this->assertTrue($this->fetch()['success']);
    }

    public function test_a_lower_limit_refuses_the_response(): void
    {
        config(['statamic.mcp.security.max_response_size' => 1000]);

        $result = $this->fetch();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Response too large', implode(' ', $result['errors']));
    }

    public function test_a_raised_limit_allows_a_response_the_default_would_refuse(): void
    {
        config(['statamic.mcp.security.max_response_size' => 1000]);
        $this->assertFalse($this->fetch()['success']);

        config(['statamic.mcp.security.max_response_size' => 500000]);
        $this->assertTrue($this->fetch()['success']);
    }

    public function test_zero_disables_the_guard(): void
    {
        config(['statamic.mcp.security.max_response_size' => 0]);

        $this->assertTrue($this->fetch()['success']);
    }
}
