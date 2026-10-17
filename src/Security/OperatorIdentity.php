<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * How operator-security state is keyed: the same (class, identifier) pair
 * role assignments use, so a grace window or a bypass follows the operator
 * the gate resolves.
 */
final class OperatorIdentity
{
    /**
     * @return array{assignee_type: string, assignee_id: string}
     */
    public static function key(Authenticatable $user): array
    {
        return [
            'assignee_type' => $user::class,
            'assignee_id'   => (string) $user->getAuthIdentifier(),
        ];
    }

    /**
     * A log- and mail-safe label: the class basename and identifier, never an email.
     */
    public static function label(Authenticatable $user): string
    {
        return class_basename($user) . '#' . $user->getAuthIdentifier();
    }
}
