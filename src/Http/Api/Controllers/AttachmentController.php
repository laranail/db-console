<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Http\Api\BatchResponse;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Services\Results\BatchResult;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Validation\Requests\AttachRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\DetachRequest;

/**
 * Attach accounts to databases, and detach them, in bulk over
 * PrivilegeManager (which authorizes `attach` / `detach` per server). Every
 * user-database pairing is reported, and a failed one does not stop the rest.
 */
final class AttachmentController
{
    public function store(AttachRequest $request, string $server, PrivilegeManager $privileges): JsonResponse
    {
        $preset = PrivilegePreset::from((string) $request->validated('preset'));
        /** @var list<string> $custom */
        $custom = (array) ($request->validated('privileges') ?? []);
        $set = $preset === PrivilegePreset::Custom ? PrivilegeSet::custom($custom) : PrivilegeSet::fromPreset($preset);

        return BatchResponse::make($this->each(
            $request,
            fn (Username $user, Host $host, array $databases): BatchResult => $privileges->attach($server, $user, $host, $databases, $set),
        ));
    }

    /**
     * Detaching removes access, so it revokes the full preset.
     */
    public function destroy(DetachRequest $request, string $server, PrivilegeManager $privileges): JsonResponse
    {
        $set = PrivilegeSet::fromPreset(PrivilegePreset::Full);

        return BatchResponse::make($this->each(
            $request,
            fn (Username $user, Host $host, array $databases): BatchResult => $privileges->detach($server, $user, $host, $databases, $set),
        ));
    }

    /**
     * @param callable(Username, Host, list<DbName>): BatchResult $run
     */
    private function each(AttachRequest|DetachRequest $request, callable $run): BatchResult
    {
        /** @var list<string> $names */
        $names = (array) $request->validated('databases');
        $databases = array_map(static fn (string $name): DbName => new DbName($name), $names);

        $pairings = [];
        /** @var list<array{username: string, host: string}> $users */
        $users = (array) $request->validated('users');
        foreach ($users as $user) {
            $pairings = [...$pairings, ...$run(new Username($user['username']), new Host($user['host']), $databases)->pairings];
        }

        return new BatchResult($pairings);
    }
}
