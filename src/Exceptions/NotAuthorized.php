<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class NotAuthorized extends AuthorizationException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::NotAuthorized;
    }

    public static function forAbility(string $ability, ?string $scope = null): self
    {
        $where = $scope ?? 'any scope';

        return new self(
            message: "gate denied '{$ability}' at {$where}",
            userParams: ['ability' => $ability, 'scope' => $where],
            context: ['ability' => $ability, 'scope' => $scope],
        );
    }

    /**
     * A token may never carry abilities its issuer does not hold.
     *
     * @param  list<string>  $abilities
     */
    public static function forTokenAbilities(array $abilities): self
    {
        $list = implode(', ', $abilities);

        return new self(
            message: "cannot issue a token with abilities the operator lacks: {$list}",
            userParams: ['abilities' => $list],
            context: ['excess_abilities' => $abilities],
        );
    }

    public static function tokensUnavailable(): self
    {
        return new self(
            message: 'the user model does not support API tokens (install laravel/sanctum and use HasApiTokens)',
            userParams: [],
            context: [],
        );
    }
}
