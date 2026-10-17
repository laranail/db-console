<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Phpseclib;

use RuntimeException;
use phpseclib4\Net\SSH2;
use phpseclib4\Net\SSH2\MessageType;
use phpseclib4\Common\Functions\Strings;

/**
 * phpseclib's SSH2 client, with the two forwarding channels it has no public
 * API for: `direct-tcpip` (RFC 4254 7.2) and OpenSSH's
 * `direct-streamlocal@openssh.com` (a Unix socket on the SSH host). Everything
 * else (window accounting, buffering packets for other channels, close) is
 * phpseclib's own channel machinery, reached through its protected methods.
 */
final class ForwardingSsh2 extends SSH2
{
    /** Window and maximum packet size offered for each forwarded channel. */
    private const int PACKET_SIZE = 0x4000;

    private int $blockingTimeout = 10;

    public function setBlockingTimeout(int $seconds): void
    {
        $this->blockingTimeout = max(1, $seconds);
    }

    /**
     * Open a channel to a TCP host and port, as seen from the SSH server.
     */
    public function openTcp(int $channel, string $host, int $port): void
    {
        // Target host and port, then the originator's address and port.
        $this->openForward($channel, 'direct-tcpip', Strings::packSSH2('sNsN', $host, $port, '127.0.0.1', 0));
    }

    /**
     * Open a channel to a Unix socket on the SSH server.
     */
    public function openSocket(int $channel, string $path): void
    {
        // The path, then two reserved fields: an empty string and a zero.
        $this->openForward($channel, 'direct-streamlocal@openssh.com', Strings::packSSH2('ssN', $path, '', 0));
    }

    /**
     * Data waiting on a channel: a string, null when there is none yet, or
     * false once the channel is closed. Never blocks for long, so one loop can
     * serve every channel.
     */
    public function readForward(int $channel): string|false|null
    {
        if (! isset($this->channel_status[$channel])) {
            return false;
        }

        $this->curTimeout = 0.005;
        $this->is_timeout = false;

        $data = $this->get_channel_packet($channel);
        if (is_string($data)) {
            return $data;
        }

        return isset($this->channel_status[$channel]) ? null : false;
    }

    public function writeForward(int $channel, string $data): void
    {
        // A full window waits for the server to adjust it, so give that a
        // real timeout rather than the poll interval readForward() left.
        $this->curTimeout = $this->blockingTimeout;
        $this->is_timeout = false;

        $this->send_channel_packet($channel, $data);
    }

    public function closeForward(int $channel): void
    {
        if (isset($this->channel_status[$channel])) {
            $this->close_channel($channel);
        }
    }

    private function openForward(int $channel, string $type, string $fields): void
    {
        if (isset($this->channel_status[$channel])) {
            throw new RuntimeException("channel {$channel} is already open");
        }

        $this->window_size_server_to_client[$channel] = $this->window_size;

        $this->curTimeout = $this->blockingTimeout;
        $this->is_timeout = false;
        $this->send_binary_packet(
            Strings::packSSH2('CsN3', MessageType::CHANNEL_OPEN, $type, $channel, $this->window_size, self::PACKET_SIZE) . $fields,
        );
        $this->channel_status[$channel] = MessageType::CHANNEL_OPEN;

        // phpseclib handles OPEN_CONFIRMATION, and treats OPEN_FAILURE as fatal
        // to the whole connection, which the pump then reports. It returns
        // true on a timeout too, so confirm the server assigned its channel.
        $this->get_channel_packet($channel);
        if ($this->isTimeout() || ! isset($this->server_channels[$channel])) {
            unset($this->channel_status[$channel]);

            throw new RuntimeException("the SSH server did not confirm the {$type} channel");
        }

        $this->channel_status[$channel] = MessageType::CHANNEL_DATA;
    }
}
