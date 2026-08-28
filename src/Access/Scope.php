<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access;

use InvalidArgumentException;
use Simtabi\Laranail\DBConsole\Enums\ScopeType;

/**
 * A parsed RBAC scope. One of:
 *   global
 *   server:<server>
 *   database:<server>/<database-pattern>   (pattern may end in *)
 *
 * Scope strings are the single wire form used by services, the CLI, the API,
 * and the UI; this value object is the one place that parses and reasons
 * about coverage (global ⊇ server ⊇ database).
 */
final readonly class Scope
{
    private function __construct(
        public ScopeType $type,
        public ?string $server,
        public ?string $databasePattern,
    ) {}

    public static function global(): self
    {
        return new self(ScopeType::Global, null, null);
    }

    public static function server(string $server): self
    {
        return new self(ScopeType::Server, $server, null);
    }

    public static function database(string $server, string $pattern): self
    {
        return new self(ScopeType::Database, $server, $pattern);
    }

    /**
     * Parse a scope string. Null is treated as global (no specific scope).
     */
    public static function parse(?string $scope): self
    {
        if ($scope === null || $scope === 'global') {
            return self::global();
        }

        if (str_starts_with($scope, 'server:')) {
            return self::server(substr($scope, strlen('server:')));
        }

        if (str_starts_with($scope, 'database:')) {
            $target = substr($scope, strlen('database:'));
            $parts = explode('/', $target, 2);
            if (count($parts) !== 2) {
                throw new InvalidArgumentException("invalid database scope '{$scope}'");
            }

            return self::database($parts[0], $parts[1]);
        }

        throw new InvalidArgumentException("invalid scope '{$scope}'");
    }

    /**
     * Rebuild the wire form.
     */
    public function toString(): string
    {
        return match ($this->type) {
            ScopeType::Global   => 'global',
            ScopeType::Server   => 'server:' . $this->server,
            ScopeType::Database => 'database:' . $this->server . '/' . $this->databasePattern,
        };
    }

    /**
     * Whether THIS scope (an assignment's scope) covers the given TARGET
     * scope. Widest covers narrowest; never the reverse.
     *
     * global    covers everything
     * server:X  covers server:X and any database:X/...
     * db:X/pat  covers database:X/<name> when <name> matches pat; covers
     *           nothing at server granularity
     */
    public function covers(self $target): bool
    {
        return match ($this->type) {
            ScopeType::Global => true,

            ScopeType::Server => $target->server === $this->server
                && in_array($target->type, [ScopeType::Server, ScopeType::Database], true),

            ScopeType::Database => $target->type === ScopeType::Database
                && $target->server === $this->server
                && $this->patternMatches((string) $this->databasePattern, (string) $target->databasePattern),
        };
    }

    /**
     * A database pattern matches a concrete database name. A trailing *
     * is a prefix wildcard (shop_* matches shop_prod); otherwise exact.
     */
    private function patternMatches(string $pattern, string $name): bool
    {
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($name, substr($pattern, 0, -1));
        }

        return $pattern === $name;
    }
}
