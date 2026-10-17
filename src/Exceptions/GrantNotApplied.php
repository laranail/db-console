<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * A GRANT reported success without granting everything. Postgres does this
 * when the admin holds a privilege without the grant option: on a schema or
 * table another role owns it warns, grants what it can, and succeeds. The
 * grant was rolled back; nothing was granted.
 */
final class GrantNotApplied extends ExecutionException
{
    /**
     * @param list<array{object: string, privilege: string}> $missing
     */
    public static function missing(string $database, string $account, array $missing): self
    {
        $list = implode(', ', array_map(
            static fn (array $row): string => "{$row['privilege']} on {$row['object']}",
            $missing,
        ));

        return new self(
            message: "grant to '{$account}' on '{$database}' was not applied; the admin could not grant: {$list}",
            userParams: ['account' => $account, 'database' => $database, 'missing' => $list],
            context: ['account' => $account, 'database' => $database, 'missing' => $missing],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::GrantNotApplied;
    }
}
