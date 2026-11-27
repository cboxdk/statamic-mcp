<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Cboxdk\StatamicMcp\Mcp\Transport\BoundedLocalRegistrar;
use Cboxdk\StatamicMcp\Support\WireInputBudget;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Registrar;

final class BoundedStdioProbeServer extends Server
{
    public function start(): void
    {
        $this->transport->onReceive(function (string $message): void {
            $this->transport->send($message);
        });
    }
}

Container::getInstance()->instance(WireInputBudget::class, new WireInputBudget(maxBytes: 128));
$registrar = new Registrar;
$registrar->local('host-owned', BoundedStdioProbeServer::class);
BoundedLocalRegistrar::register($registrar, 'statamic', BoundedStdioProbeServer::class);
if ($registrar->getLocalServer('host-owned') === null) {
    throw new RuntimeException('Package registration removed a host server.');
}
$run = $registrar->getLocalServer('statamic');
if ($run === null) {
    throw new RuntimeException('Package server was not registered.');
}
$run();
