<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Relay;

use InvalidArgumentException;

/**
 * One MySQL protocol packet: a sequence number and its payload. On the wire
 * it is a 3-byte little-endian length, the sequence byte, then the payload.
 */
final readonly class MysqlPacket
{
    /** The largest payload one packet carries; longer ones continue in the next. */
    public const int MAX_PAYLOAD = 0xFF_FFFF;

    public function __construct(
        public int $sequence,
        public string $payload,
    ) {
        if (strlen($payload) > self::MAX_PAYLOAD) {
            throw new InvalidArgumentException('a MySQL packet payload is at most 16 MiB - 1');
        }
    }

    public function encode(): string
    {
        $length = strlen($this->payload);

        return chr($length & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length >> 16)
            . chr($this->sequence & 0xFF) . $this->payload;
    }

    public function withSequence(int $sequence): self
    {
        return new self($sequence & 0xFF, $this->payload);
    }

    public function first(): int
    {
        return $this->payload === '' ? -1 : ord($this->payload[0]);
    }
}
