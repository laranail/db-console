<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Http\Api\OperationResponse;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Validation\Requests\DropDatabaseRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\CreateDatabaseRequest;

/**
 * Databases API. Thin: it validates via the SHARED FormRequests and calls the
 * DatabaseManager — the same service (and the same Gate) the CLI and UI use.
 */
final class DatabaseController
{
    public function index(Request $request, string $server, DatabaseManager $databases): JsonResponse
    {
        return new JsonResponse(['data' => $databases->list($server)]);
    }

    public function store(CreateDatabaseRequest $request, string $server, DatabaseManager $databases): JsonResponse
    {
        $charset = (string) ($request->validated('charset') ?? 'utf8mb4');
        $collation = (string) ($request->validated('collation') ?? 'utf8mb4_unicode_ci');

        $result = $databases->create(
            $server,
            new DbName((string) $request->validated('name')),
            new Charset($charset, $collation),
            ExistingResourcePolicy::tryFrom((string) $request->validated('on_existing')),
        );

        return OperationResponse::make($result, $result->alreadyExisted ? 200 : 201);
    }

    /**
     * The body repeats the name as `confirm` (unless api.confirm_destructive
     * is off); DropDatabaseRequest validates it.
     */
    public function destroy(DropDatabaseRequest $request, string $server, string $name, DatabaseManager $databases): JsonResponse
    {
        return OperationResponse::make($databases->drop($server, new DbName((string) $request->validated('name'))));
    }
}
