<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Api;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;

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
     * @param list<string> $abilities requested ConsolePermission values
     *
     * @return array{token: string, abilities: list<string>}
     */
    public function issue(Authenticatable $operator, string $name, array $abilities, ?DateTimeInterface $expiresAt = null): array
    {
        return $this->mint($operator, $this->grantedAbilities($operator), $name, $abilities, $expiresAt);
    }

    /**
     * Issue a token to $holder on $caller's request. Its abilities are capped
     * by both: the caller cannot hand out more than they hold, and the token
     * cannot do more than its holder may. Without the second cap an Admin
     * could mint a token for an Owner carrying the Owner's abilities, and
     * without the first a caller could mint one beyond their own.
     *
     * @param list<string> $abilities requested ConsolePermission values
     *
     * @return array{token: string, abilities: list<string>}
     */
    public function issueFor(Authenticatable $caller, Authenticatable $holder, string $name, array $abilities, ?DateTimeInterface $expiresAt = null): array
    {
        $granted = array_values(array_intersect($this->grantedAbilities($holder), $this->grantedAbilities($caller)));

        return $this->mint($holder, $granted, $name, $abilities, $expiresAt);
    }

    /**
     * @param list<string> $granted
     * @param list<string> $abilities
     *
     * @return array{token: string, abilities: list<string>}
     */
    private function mint(Authenticatable $holder, array $granted, string $name, array $abilities, ?DateTimeInterface $expiresAt): array
    {
        // Default to the full permitted set when none requested.
        $requested = $abilities === [] ? $granted : $abilities;

        $excess = array_values(array_diff($requested, $granted));
        if ($excess !== []) {
            throw NotAuthorized::forTokenAbilities($excess);
        }

        if (! method_exists($holder, 'createToken')) {
            throw NotAuthorized::tokensUnavailable();
        }

        /** @var object{plainTextToken: string} $token */
        $token = $holder->createToken($name, $requested, $expiresAt);

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
