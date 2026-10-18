<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Models\AuditLog;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;

/**
 * Read-only audit query for the API (the trail is history; secret-free).
 */
final class AuditController
{
    /**
     * Needs audit.view: the trail was readable by any authenticated caller.
     */
    public function index(Request $request, Authorizer $authorizer): JsonResponse
    {
        $authorizer->authorize(ConsolePermission::AuditView, 'global');

        $rows = AuditLog::query()->latest('created_at')->limit((int) $request->integer('limit', 25))->get()
            ->map(static fn (AuditLog $row): array => [
                'action'  => $row->action->value,
                'server'  => $row->server,
                'target'  => $row->target,
                'outcome' => $row->outcome->value,
                'at'      => $row->created_at?->toIso8601String(),
            ])->all();

        return new JsonResponse(['data' => $rows]);
    }
}
