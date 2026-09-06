<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Http\Api\OperationResponse;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Validation\Requests\GrantRequest;

/**
 * Grants API over PrivilegeManager (shared service + Gate + forbidden-privilege guard).
 */
final class GrantController
{
    public function store(GrantRequest $request, string $server, PrivilegeManager $privileges): JsonResponse
    {
        $preset = PrivilegePreset::from((string) $request->validated('preset'));
        /** @var list<string> $custom */
        $custom = (array) ($request->validated('privileges') ?? []);
        $set = $preset === PrivilegePreset::Custom ? PrivilegeSet::custom($custom) : PrivilegeSet::fromPreset($preset);

        $result = $privileges->grant(
            $server,
            new Username((string) $request->validated('username')),
            new Host((string) ($request->validated('host') ?? '%')),
            new DbName((string) $request->validated('database')),
            $set,
        );

        return OperationResponse::make($result, 201);
    }
}
