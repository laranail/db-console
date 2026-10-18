<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Tunnels\Phpseclib\ForwardingSsh2;

/**
 * phpseclib raises its read timeout to 5 seconds when it handles another
 * channel's CHANNEL_CLOSE mid-read. During a poll that must not happen, or
 * one idle channel's poll blocks every session through the pump. The live
 * proof is "keeps other sessions responsive" in LiveSshTunnelTest; this pins
 * the clamp itself, by writing the timeout the way phpseclib's own code does.
 */
it('never lets a poll wait longer than the poll timeout', function (): void {
    // Lazy: the constructor connects nothing until a method needs the socket.
    $ssh = new ForwardingSsh2('ssh.invalid', 22, 10);

    $timeoutAfterClose = Closure::bind(function (bool $polling): int|float|null {
        $this->polling = $polling;
        $this->curTimeout = 5;   // what get_channel_packet() does on a CHANNEL_CLOSE

        return $this->curTimeout;
    }, $ssh, ForwardingSsh2::class);

    expect($timeoutAfterClose(true))->toBe(0.005)
        ->and($timeoutAfterClose(false))->toBe(5);
});

/*
 * phpseclib drops a received CHANNEL_EOF without recording it. The pump needs
 * it to tell the local client the database closed its side; without that,
 * libpq waits forever after a FATAL error. Fed here the way phpseclib's log
 * path hands each received packet over.
 */
it('notes the channels the server sent EOF on, and nothing else', function (): void {
    $ssh = new ForwardingSsh2('ssh.invalid', 22, 10);

    $receive = Closure::bind(function (string $type, int $channel): void {
        $numbers = $messages = [];
        $size = null;
        $file = null;
        $wrap = null;
        $realtime = null;

        $this->append_log_helper(1, "<- SSH_MSG_{$type} (since last: 0, network: 0s)", chr(96) . pack('N', $channel), $numbers, $messages, $size, $file, $wrap, $realtime);
    }, $ssh, ForwardingSsh2::class);

    $receive('CHANNEL_DATA', 100);
    $receive('CHANNEL_EOF', 101);

    expect($ssh->remoteFinished(101))->toBeTrue()
        ->and($ssh->remoteFinished(100))->toBeFalse();
});
