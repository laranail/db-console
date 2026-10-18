<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Api\UserResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Validation\Requests\RoleAssignmentRequest;

/**
 * Assign a console role to a user at a scope. Assigning roles is how
 * privilege is granted, so it needs `settings.manage`, which only Owner
 * holds, plus whatever assurance security.assurance asks of it. A role that
 * grants nothing is refused rather than stored.
 */
final class RoleAssignmentController
{
    public function store(RoleAssignmentRequest $request, RbacDriver $driver, UserResolver $users, Authorizer $authorizer): JsonResponse
    {
        $authorizer->authorize(ConsolePermission::SettingsManage, 'global');

        $role = (string) $request->validated('role');
        if ($driver->permissionsForRole($role) === []) {
            return new JsonResponse(['message' => "Unknown role '{$role}'.", 'errors' => ['role' => ["Unknown role '{$role}'."]]], 422);
        }

        $assignee = $users->find((string) $request->validated('user_id'));
        if (! $assignee instanceof Authenticatable) {
            return new JsonResponse(['message' => 'No such user.'], 404);
        }

        $scope = (string) $request->validated('scope');
        $driver->assign($assignee, $role, $scope);

        return new JsonResponse(['data' => [
            'user_id' => $assignee->getAuthIdentifier(),
            'role'    => $role,
            'scope'   => $scope,
        ]], 201);
    }
}
