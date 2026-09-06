<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Http\Api\OperationResponse;
use Simtabi\Laranail\DBConsole\Validation\Requests\CreateAccountRequest;

/**
 * Accounts API over AccountManager (shared service + Gate).
 */
final class AccountController
{
    public function index(Request $request, string $server, AccountManager $accounts): JsonResponse
    {
        return new JsonResponse(['data' => $accounts->list($server)]);
    }

    public function store(CreateAccountRequest $request, string $server, AccountManager $accounts): JsonResponse
    {
        $passwordInput = $request->validated('password');
        $password = is_string($passwordInput) && $passwordInput !== '' ? new Password($passwordInput) : null;

        $result = $accounts->create(
            $server,
            new Username((string) $request->validated('username')),
            new Host((string) ($request->validated('host') ?? 'localhost')),
            $password,
        );

        return OperationResponse::make($result, 201);
    }

    public function destroy(Request $request, string $server, string $username, AccountManager $accounts): JsonResponse
    {
        if ($request->input('confirm') !== $username) {
            return new JsonResponse(['message' => "Confirmation required: send {\"confirm\":\"{$username}\"} to drop this account."], 422);
        }

        $host = (string) ($request->input('host') ?? '%');

        return OperationResponse::make($accounts->drop($server, new Username($username), new Host($host)));
    }
}
