<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Api\TokenManager;
use Simtabi\Laranail\DBConsole\Api\UserResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Validation\Requests\TokenIssueRequest;

/**
 * Issue an API token to a console user. Needs `token.manage`, and the
 * token's abilities are capped by both the caller's and the holder's (see
 * TokenManager::issueFor). The plain token is returned once.
 */
final class TokenController
{
    public function store(TokenIssueRequest $request, TokenManager $tokens, UserResolver $users, Authorizer $authorizer): JsonResponse
    {
        $authorizer->authorize(ConsolePermission::TokenManage, 'global');

        $holder = $users->find((string) $request->validated('user_id'));
        if (! $holder instanceof Authenticatable) {
            return new JsonResponse(['message' => 'No such user.'], 404);
        }

        $caller = $request->user();
        if (! $caller instanceof Authenticatable) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $expires = $request->validated('expires_at');
        /** @var list<string> $abilities */
        $abilities = (array) ($request->validated('abilities') ?? []);

        $issued = $tokens->issueFor(
            $caller,
            $holder,
            (string) $request->validated('name'),
            $abilities,
            is_string($expires) ? Carbon::parse($expires) : null,
        );

        return new JsonResponse(['data' => $issued], 201);
    }
}
