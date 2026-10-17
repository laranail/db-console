<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Http\Api\ApiSettings;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Http\Api\OperationResponse;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
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
            ExistingResourcePolicy::tryFrom((string) $request->validated('on_existing')),
        );

        // 201 for a new account; 200 for one adopted as it was found. A
        // refused one never gets here: AccountAlreadyExists renders the 409.
        return OperationResponse::make($result, $result->alreadyExisted ? 200 : 201);
    }

    public function destroy(Request $request, string $server, string $username, AccountManager $accounts, ApiSettings $settings): JsonResponse
    {
        // api.confirm_destructive (default true): the body must repeat the name.
        if ($settings->confirmsDestructive() && $request->input('confirm') !== $username) {
            return new JsonResponse(['message' => "Confirmation required: send {\"confirm\":\"{$username}\"} to drop this account."], 422);
        }

        $host = (string) ($request->input('host') ?? '%');

        return OperationResponse::make($accounts->drop($server, new Username($username), new Host($host)));
    }
}
