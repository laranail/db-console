<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Simtabi\Laranail\DBConsole\Models\AuditLog;

/**
 * Read-only audit query for the API (the trail is history; secret-free).
 */
final class AuditController
{
    public function index(Request $request): JsonResponse
    {
        $rows = AuditLog::query()->latest('created_at')->limit((int) $request->integer('limit', 25))->get()
            ->map(static fn (AuditLog $row): array => [
                'action' => $row->action->value,
                'server' => $row->server,
                'target' => $row->target,
                'outcome' => $row->outcome->value,
                'at' => $row->created_at?->toIso8601String(),
            ])->all();

        return new JsonResponse(['data' => $rows]);
    }
}
