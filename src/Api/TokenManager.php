<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Api;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;

/**
 * Issues API tokens (Sanctum personal access tokens) whose abilities are the
 * DBConsole permissions the token may exercise. The security invariant: a
 * token's abilities can NEVER exceed the issuing operator's own permissions
 * at global scope — you cannot mint a token more powerful than yourself.
 * Requested abilities beyond the operator's are rejected, not silently
 * dropped.
 */
final readonly class TokenManager
{
    public function __construct(private AccessManager $access) {}

    /**
     * @param  list<string>  $abilities  requested ConsolePermission values
     * @return array{token: string, abilities: list<string>}
     */
    public function issue(Authenticatable $operator, string $name, array $abilities): array
    {
        $granted = $this->grantedAbilities($operator);

        // Default to the operator's full ability set when none requested.
        $requested = $abilities === [] ? $granted : $abilities;

        $excess = array_values(array_diff($requested, $granted));
        if ($excess !== []) {
            throw NotAuthorized::forTokenAbilities($excess);
        }

        if (! method_exists($operator, 'createToken')) {
            throw NotAuthorized::tokensUnavailable();
        }

        /** @var object{plainTextToken: string} $token */
        $token = $operator->createToken($name, $requested);

        return ['token' => $token->plainTextToken, 'abilities' => $requested];
    }

    /**
     * The ConsolePermission values the operator actually holds at global
     * scope — the ceiling for any token they issue.
     *
     * @return list<string>
     */
    private function grantedAbilities(Authenticatable $operator): array
    {
        $granted = [];
        foreach (ConsolePermission::cases() as $permission) {
            if ($this->access->allows($operator, $permission, 'global')) {
                $granted[] = $permission->value;
            }
        }

        return $granted;
    }
}
