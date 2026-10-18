<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Relay;

use UnexpectedValueException;

/**
 * The few edits the verifying relay makes to MySQL's connection phase.
 *
 * MySQL starts TLS inside its own protocol: the server greets in plaintext,
 * the client answers with an SSLRequest (the first 32 bytes of its handshake
 * response, with CLIENT_SSL set) and starts TLS, then sends the full response
 * as packet 2. The relay plays the client's part towards the server, and
 * hides TLS from the real client, which talks plaintext over a private socket
 * and sends its response as packet 1. Every connection-phase packet after it
 * is therefore one sequence number apart on the two sides, until OK or ERR;
 * commands after that start again at 0 on both.
 *
 * @see https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_connection_phase.html
 */
final class MysqlHandshake
{
    public const int CLIENT_PROTOCOL_41 = 0x0000_0200;

    public const int CLIENT_SSL = 0x0000_0800;

    /** CR_SSL_CONNECTION_ERROR, the client error for a TLS set-up that failed. */
    private const int SSL_CONNECTION_ERROR = 2026;

    public static function serverSupportsSsl(MysqlPacket $greeting): bool
    {
        return (self::capabilities($greeting) & self::CLIENT_SSL) !== 0;
    }

    /**
     * The server's capability flags from its greeting: the lower 16 bits after
     * the auth data's first part, the upper 16 after charset and status.
     */
    public static function capabilities(MysqlPacket $greeting): int
    {
        $offset = self::lowerCapabilityOffset($greeting);
        $lower = self::uint16($greeting->payload, $offset);
        $upper = strlen($greeting->payload) >= $offset + 7 ? self::uint16($greeting->payload, $offset + 5) : 0;

        return $lower | ($upper << 16);
    }

    /**
     * The greeting with CLIENT_SSL cleared, so the real client never asks the
     * relay for TLS. The relay does TLS to the server itself.
     */
    public static function withoutSsl(MysqlPacket $greeting): MysqlPacket
    {
        $offset = self::lowerCapabilityOffset($greeting);
        $lower = self::uint16($greeting->payload, $offset) & ~self::CLIENT_SSL;

        $payload = $greeting->payload;
        $payload[$offset] = chr($lower & 0xFF);
        $payload[$offset + 1] = chr(($lower >> 8) & 0xFF);

        return new MysqlPacket($greeting->sequence, $payload);
    }

    /**
     * The SSLRequest the relay sends the server in the client's place: the
     * client's capabilities with CLIENT_SSL, its max packet size and charset,
     * and 23 bytes of filler. It takes the response's own sequence number.
     */
    public static function sslRequest(MysqlPacket $response): MysqlPacket
    {
        $capabilities = self::clientCapabilities($response);

        return new MysqlPacket(
            $response->sequence,
            pack('V', $capabilities | self::CLIENT_SSL) . substr($response->payload, 4, 5) . str_repeat("\0", 23),
        );
    }

    /**
     * The client's handshake response as the server expects it after the
     * SSLRequest: CLIENT_SSL set, and one sequence number later.
     */
    public static function afterSslRequest(MysqlPacket $response): MysqlPacket
    {
        $capabilities = self::clientCapabilities($response);

        return new MysqlPacket(
            $response->sequence + 1,
            pack('V', $capabilities | self::CLIENT_SSL) . substr($response->payload, 4),
        );
    }

    /**
     * OK or ERR closes the connection phase. More auth data (0x01) or an auth
     * switch (0xFE) means the exchange continues.
     */
    public static function endsConnectionPhase(MysqlPacket $serverPacket): bool
    {
        return in_array($serverPacket->first(), [0x00, 0xFF], true);
    }

    /**
     * An ERR packet the client reports as the reason its connection failed.
     */
    public static function error(int $sequence, string $message): MysqlPacket
    {
        return new MysqlPacket($sequence, "\xff" . pack('v', self::SSL_CONNECTION_ERROR) . '#08S01' . $message);
    }

    private static function clientCapabilities(MysqlPacket $response): int
    {
        if (strlen($response->payload) < 32) {
            throw new UnexpectedValueException('the client handshake response is too short');
        }

        $capabilities = self::uint16($response->payload, 0) | (self::uint16($response->payload, 2) << 16);
        if (($capabilities & self::CLIENT_PROTOCOL_41) === 0) {
            throw new UnexpectedValueException('the client does not speak MySQL protocol 4.1, which TLS needs');
        }

        return $capabilities;
    }

    private static function lowerCapabilityOffset(MysqlPacket $greeting): int
    {
        $payload = $greeting->payload;
        $end = strpos($payload, "\0", 1);

        if ($greeting->first() !== 10 || $end === false || strlen($payload) < $end + 1 + 4 + 8 + 1 + 2) {
            throw new UnexpectedValueException('not a MySQL protocol 10 server greeting');
        }

        // Version string and its NUL, connection id, auth data part 1, filler.
        return $end + 1 + 4 + 8 + 1;
    }

    private static function uint16(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
    }
}
