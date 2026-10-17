<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Encryption;

use Illuminate\Database\ConnectionInterface;

/**
 * Asks a live session whether it is encrypted. The answer comes from the
 * server, not from config, so a TLS setting that was never applied shows up
 * as plaintext instead of as a reassuring status.
 */
interface TlsProbe
{
    /**
     * True when the session is encrypted, false when it is plaintext, and
     * null when the driver offers no way to ask or the question failed.
     */
    public function isEncrypted(ConnectionInterface $connection): ?bool;
}
