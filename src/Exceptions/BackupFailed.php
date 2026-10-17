<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Throwable;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * The snapshot taken before a drop failed, so the drop did not run. A drop
 * whose backup failed is unrecoverable, which is the one thing
 * backup-before-drop exists to prevent.
 */
final class BackupFailed extends ExecutionException
{
    public static function beforeDrop(string $server, string $database, ?Throwable $previous = null): self
    {
        return new self(
            message: "the backup of '{$database}' on server '{$server}' failed, so it was not dropped",
            userParams: ['server' => $server, 'target' => $database],
            context: ['server' => $server, 'target' => $database, 'error' => $previous?->getMessage()],
            previous: $previous,
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::BackupFailed;
    }
}
