<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Illuminate\Database\ConnectionInterface;
use Simtabi\Laranail\DBConsole\Encryption\TlsProbe;

/**
 * Answers the TLS question with a fixed value, so the checker's verdicts can
 * be tested without a live server. Never opens the connection it is given.
 */
final readonly class FakeTlsProbe implements TlsProbe
{
    public function __construct(private ?bool $encrypted) {}

    public function isEncrypted(ConnectionInterface $connection): ?bool
    {
        return $this->encrypted;
    }
}
