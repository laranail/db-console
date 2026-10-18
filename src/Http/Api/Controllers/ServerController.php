<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Auth\Access\Gate;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;

/**
 * Read-only server list for the API.
 */
final class ServerController
{
    /**
     * The servers this caller may see (server.view at each). It listed every
     * registered server to any authenticated caller.
     */
    public function index(ServerRegistry $registry, Gate $gate): JsonResponse
    {
        $servers = [];
        foreach ($registry->names() as $name) {
            if (! $gate->allows(ConsolePermission::ServerView->ability(), "server:{$name}")) {
                continue;
            }

            $definition = $registry->definition($name);
            $servers[] = ['name' => $name, 'engine' => $definition->engine->value];
        }

        return new JsonResponse(['data' => $servers]);
    }
}
