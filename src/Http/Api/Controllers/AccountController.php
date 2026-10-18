<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Http\Api\OperationResponse;
use Simtabi\Laranail\DBConsole\Services\Results\GrantEntry;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Validation\Requests\DropAccountRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\EditAccountRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\CreateAccountRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\RotatePasswordRequest;

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

    /**
     * The account's privileges, read live. `?host=` names the account's host
     * (default %), `?direct=1` keeps only what it holds itself.
     */
    public function grants(Request $request, string $server, string $username, PrivilegeManager $privileges): JsonResponse
    {
        $entries = $privileges->grants(
            $server,
            new Username($username),
            new Host((string) ($request->query('host') ?? '%')),
            $request->boolean('direct'),
        );

        return new JsonResponse(['data' => array_map(static fn (GrantEntry $entry): array => $entry->toArray(), $entries)]);
    }

    /**
     * Move an account to another host, grants kept; `rotate` also sets a new
     * generated password, returned once.
     */
    public function update(EditAccountRequest $request, string $server, string $username, AccountManager $accounts): JsonResponse
    {
        return OperationResponse::make($accounts->changeHost(
            $server,
            new Username($username),
            new Host((string) $request->validated('host')),
            new Host((string) $request->validated('new_host')),
            (bool) $request->validated('rotate'),
        ));
    }

    /**
     * Set the account's password: the one supplied, or a generated one
     * (returned once) with `generate`.
     */
    public function rotatePassword(RotatePasswordRequest $request, string $server, string $username, AccountManager $accounts): JsonResponse
    {
        $supplied = $request->validated('password');
        $password = is_string($supplied) && $supplied !== '' ? new Password($supplied) : null;

        return OperationResponse::make($accounts->rotatePassword(
            $server,
            new Username($username),
            new Host((string) $request->validated('host')),
            $password,
        ));
    }

    /**
     * The host is required and the body repeats the username as `confirm`
     * (unless api.confirm_destructive is off); DropAccountRequest validates
     * both.
     */
    public function destroy(DropAccountRequest $request, string $server, string $username, AccountManager $accounts): JsonResponse
    {
        return OperationResponse::make($accounts->drop($server, new Username($username), new Host((string) $request->validated('host'))));
    }
}
