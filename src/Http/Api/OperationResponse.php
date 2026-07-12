<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;

/**
 * Serialises an OperationResult to JSON for the API. The generated password
 * (if any) appears ONCE here — in the create response body — and is never
 * persisted, so a later GET can never return it.
 */
final readonly class OperationResponse
{
    public static function make(OperationResult $result, int $status = 200): JsonResponse
    {
        $body = [
            'operation' => $result->operation->value,
            'outcome' => $result->outcome->value,
            'server' => $result->server,
            'already_existed' => $result->alreadyExisted,
            'data' => $result->data,
        ];

        $password = $result->takeGeneratedPassword();
        if ($password !== null) {
            $body['generated_password'] = $password;   // shown once
        }

        return new JsonResponse($body, $status);
    }
}
