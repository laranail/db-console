<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;

/**
 * Read-only server list for the API.
 */
final class ServerController
{
    public function index(ServerRegistry $registry): JsonResponse
    {
        $servers = [];
        foreach ($registry->names() as $name) {
            $definition = $registry->definition($name);
            $servers[] = ['name' => $name, 'engine' => $definition->engine->value];
        }

        return new JsonResponse(['data' => $servers]);
    }
}
