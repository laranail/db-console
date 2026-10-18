<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Relay;

/**
 * Turns a byte stream into MySQL packets, whatever sizes it arrives in.
 */
final class MysqlPacketReader
{
    private string $buffer = '';

    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function next(): ?MysqlPacket
    {
        if (strlen($this->buffer) < 4) {
            return null;
        }

        $length = ord($this->buffer[0]) | (ord($this->buffer[1]) << 8) | (ord($this->buffer[2]) << 16);
        if (strlen($this->buffer) < 4 + $length) {
            return null;
        }

        $packet = new MysqlPacket(ord($this->buffer[3]), substr($this->buffer, 4, $length));
        $this->buffer = (string) substr($this->buffer, 4 + $length);

        return $packet;
    }

    /**
     * Bytes read past the last whole packet.
     */
    public function pending(): string
    {
        return $this->buffer;
    }
}
