<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Http\Middleware\BoundMcpInput;
use Cboxdk\StatamicMcp\ServiceProvider;
use Cboxdk\StatamicMcp\Support\InputBudget;
use Cboxdk\StatamicMcp\Support\WireInputBudget;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Registrar;
use Symfony\Component\Process\Process;

it('guards the raw body before global Laravel JSON transformations', function () {
    config()->set('statamic.mcp.web.enabled', true);
    config()->set('statamic.mcp.web.path', '/mcp/statamic');
    $this->app->instance(WireInputBudget::class, new WireInputBudget(maxBytes: 32));
    $kernel = app(KernelContract::class);
    expect($kernel)->toBeInstanceOf(Kernel::class);
    $stack = $kernel->getGlobalMiddleware();
    $guard = array_search(BoundMcpInput::class, $stack, true);
    expect($guard)->not->toBeFalse();
    foreach ([TrimStrings::class, ConvertEmptyStringsToNull::class] as $parser) {
        $position = array_search($parser, $stack, true);
        expect($position)->not->toBeFalse()->toBeGreaterThan($guard);
    }
    $this->call('POST', '/mcp/statamic', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"data":"' . str_repeat('x', 100) . '"}')->assertStatus(413);
});

it('bounds chunked bodies without relying on a declared content length and keeps CORS', function () {
    config()->set('statamic.mcp.web.enabled', true);
    config()->set('statamic.mcp.web.path', '/mcp/statamic');
    config()->set('statamic.mcp.web.allowed_origins', ['https://client.example']);
    $this->app->instance(WireInputBudget::class, new WireInputBudget(maxTokens: 4));
    $this->call('POST', '/mcp/statamic', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://client.example',
        'HTTP_TRANSFER_ENCODING' => 'chunked',
    ], '[0,1,2,3]')->assertStatus(413)->assertHeader('Access-Control-Allow-Origin', 'https://client.example');
});

it('does not consume unrelated application requests', function () {
    config()->set('statamic.mcp.web.enabled', true);
    $this->app->instance(WireInputBudget::class, new WireInputBudget(maxBytes: 1));
    $request = Request::create('/unrelated', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"data":"ordinary request"}');
    $response = app(BoundMcpInput::class)->handle($request, function (Request $request) {
        expect($request->json('data'))->toBe('ordinary request');

        return response('untouched');
    });
    expect($response->getContent())->toBe('untouched');
});

it('reassembles fragmented stdio frames and accepts a final frame at EOF', function () {
    // Use the package fixture, independent of Testbench's application directory.
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/bounded-stdio.php']);
    $process->setInput((function () {
        yield '{"jsonrpc":"2.0",';
        usleep(30000);
        yield '"id":1}' . "\n" . '{"id":2}';
    })());
    $process->mustRun();
    expect($process->getOutput())->toBe('{"jsonrpc":"2.0","id":1}' . "\n" . '{"id":2}' . "\n");
});

it('stops an oversized stdio frame without interpreting its tail as another request', function () {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/bounded-stdio.php']);
    $process->setInput(str_repeat(' ', 129) . "\n" . '{"id":"must-not-run"}' . "\n");
    $process->mustRun();
    $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    expect($result['id'])->toBeNull()->and($result['error']['code'])->toBe(-32600);
    expect($process->getOutput())->not->toContain('must-not-run');
});

it('rejects malformed stdio JSON but handles the following complete frame', function () {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/bounded-stdio.php']);
    $process->setInput("{bad}\n" . '{"id":2}' . "\n");
    $process->mustRun();
    $lines = explode("\n", trim($process->getOutput()));
    expect(json_decode($lines[0], true)['error']['code'])->toBe(-32600);
    expect($lines[1])->toBe('{"id":2}');
});

it('applies configured budgets to both raw and decoded input', function () {
    config()->set('statamic.mcp.security.max_input_bytes', 20);
    config()->set('statamic.mcp.security.max_input_values', 3);
    expect(fn () => app(WireInputBudget::class)->check('"' . str_repeat('x', 20) . '"'))->toThrow(LengthException::class);
    expect(fn () => app(InputBudget::class)->check(['value' => str_repeat('x', 20)]))->toThrow(InvalidArgumentException::class);
    config()->set('statamic.mcp.security.max_input_values', 0);
    expect(fn () => app(WireInputBudget::class))->toThrow(LogicException::class);
});

it('rejects a million-value frame without allocating its decoded array', function () {
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=32M', dirname(__DIR__, 2) . '/Fixtures/wire-input-memory.php']);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['rejected'])->toBeTrue();
    expect($result['peak_bytes'])->toBeLessThan(32 * 1024 * 1024);
});

it('registers the stdio handle even with cached HTTP routes', function () {
    $directory = sys_get_temp_dir() . '/mcp-routes-' . bin2hex(random_bytes(8));
    $original = app()->bootstrapPath();
    try {
        mkdir($directory . '/cache', 0700, true);
        app()->useBootstrapPath($directory);
        file_put_contents(app()->getCachedRoutesPath(), '<?php return [];');
        app()->forgetInstance('routes.cached');
        expect(app()->routesAreCached())->toBeTrue();
        $registrar = new Registrar;
        app()->instance(Registrar::class, $registrar);
        $provider = new ServiceProvider(app());
        (new ReflectionMethod($provider, 'registerLocalMcp'))->invoke($provider);
        expect($registrar->getLocalServer('statamic'))->toBeCallable();
    } finally {
        app()->useBootstrapPath($original);
        app()->forgetInstance('routes.cached');
        File::deleteDirectory($directory);
    }
});
