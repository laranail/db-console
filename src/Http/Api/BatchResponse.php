<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Services\Results\BatchResult;

/**
 * The JSON shape of an attach or detach run: every pairing with its outcome.
 * 200 when all succeeded, 207 when some failed, so a partial run is never
 * reported as a success.
 */
final readonly class BatchResponse
{
    public static function make(BatchResult $result): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'total'     => $result->total(),
                'succeeded' => $result->succeeded(),
                'failed'    => $result->failed(),
            ],
        ], $result->allSucceeded() ? 200 : 207);
    }
}
