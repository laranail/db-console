<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Throwable;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * An SSH tunnel to a server could not be opened or died: the host key did not
 * match the pin, authentication was refused, the forward failed, or the
 * driver is unavailable. The reason is the forwarder's own diagnosis, which
 * names hosts and key fingerprints but never key material.
 */
final class TunnelFailed extends ConnectionException
{
    public static function forServer(string $server, string $reason, ?Throwable $previous = null): self
    {
        $reason = rtrim(trim($reason), '.');

        return new self(
            message: "the SSH tunnel to server '{$server}' could not be opened: {$reason}",
            userParams: ['server' => $server, 'reason' => $reason],
            context: ['server' => $server, 'reason' => $reason],
            previous: $previous,
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::TunnelFailed;
    }
}
