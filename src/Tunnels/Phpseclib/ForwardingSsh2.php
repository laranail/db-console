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

    /** Seconds a poll of one channel may wait for its next packet. */
    private const float POLL_TIMEOUT = 0.005;

    /**
     * phpseclib's remaining wait for the packet it is reading. When it
     * handles a CHANNEL_CLOSE on another channel during a read, it raises this
     * to 5 seconds and keeps reading, so a poll of an idle channel would block
     * the whole pump, and every other session through it, until that timeout
     * ran out. While polling, the wait is never raised past the poll's own.
     * The hook applies to writes from phpseclib's code too, which is the point.
     */
    protected int|float|null $curTimeout = null {
        set(int|float|null $value) {
            $this->curTimeout = $this->polling && $value !== null && $value > self::POLL_TIMEOUT
                ? self::POLL_TIMEOUT
                : $value;
        }
    }

    private int $blockingTimeout = 10;

    /** True while readForward() polls a channel. */
    private bool $polling = false;

    /** @var array<int, true> channels the server has sent CHANNEL_EOF on */
    private array $remoteEof = [];

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

        $this->polling = true;
        $this->curTimeout = self::POLL_TIMEOUT;
        $this->is_timeout = false;

        try {
            $data = $this->get_channel_packet($channel);
        } finally {
            $this->polling = false;
        }

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

    /**
     * Whether the server has finished sending on a channel: the far end
     * (the database) closed its side. Whatever it sent before that has
     * already been returned by readForward() once that returns null.
     */
    public function remoteFinished(int $channel): bool
    {
        return isset($this->remoteEof[$channel]);
    }

    public function closeForward(int $channel): void
    {
        unset($this->remoteEof[$channel]);

        if (isset($this->channel_status[$channel])) {
            $this->close_channel($channel);
        }
    }

    /**
     * phpseclib drops a received CHANNEL_EOF without recording it anywhere,
     * so nothing in its state says the far end has closed. With
     * NET_SSH2_LOGGING defined (ForwardPump defines it), every received
     * packet passes through here; the EOFs are noted and nothing is kept,
     * so the log never grows.
     *
     * @param array<int, mixed> $message_number_log
     * @param array<int, mixed> $message_log
     */
    protected function append_log_helper(
        int $constant,
        string $message_number,
        string $message,
        array &$message_number_log,
        array &$message_log,
        ?int &$log_size,
        mixed &$realtime_log_file,
        ?bool &$realtime_log_wrap,
        ?int &$realtime_log_size,
    ): void {
        // The payload is the message type, then the recipient channel: ours.
        if (str_starts_with($message_number, '<- SSH_MSG_CHANNEL_EOF ') && strlen($message) >= 5) {
            $channel = unpack('N', substr($message, 1, 4));

            if (is_array($channel) && is_int($channel[1] ?? null)) {
                $this->remoteEof[$channel[1]] = true;
            }
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
