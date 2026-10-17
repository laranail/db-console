<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Tunnels\HostKey;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\TunnelKeys;

it('refuses to run without a pin: there is no trust on first use', function (?string $line): void {
    expect(fn (): HostKey => HostKey::parse($line))->toThrow(InvalidArgumentException::class, 'never trusts a host key on first use');
})->with([null, '', '   ']);

it('refuses something that is not a public key line', function (string $line): void {
    expect(fn (): HostKey => HostKey::parse($line))->toThrow(InvalidArgumentException::class);
})->with([
    'a fingerprint'          => TunnelKeys::PINNED_FINGERPRINT,
    'no key data'            => 'ssh-ed25519',
    'not base64'             => 'ssh-ed25519 not-base64!!',
    'key under another type' => 'ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAINhPMVpDM4Rag4mH54u7tQiLouDwLRICYFI63kWc3NV6',
]);

it('reads a key line, a commented one, and a known_hosts line alike', function (string $line): void {
    $key = HostKey::parse($line);

    expect($key->type)->toBe('ssh-ed25519')
        ->and($key->fingerprint())->toBe(TunnelKeys::PINNED_FINGERPRINT);
})->with([
    TunnelKeys::PINNED,
    TunnelKeys::PINNED . ' root@bastion',
    '[bastion.example.com]:2222 ' . TunnelKeys::PINNED,
]);

it('pins a non-default port with the [host]:port pattern OpenSSH matches on', function (): void {
    $key = HostKey::parse(TunnelKeys::PINNED);

    expect($key->knownHostsLine('bastion.example.com', 22))->toBe('bastion.example.com ' . TunnelKeys::PINNED)
        ->and($key->knownHostsLine('bastion.example.com', 2222))->toBe('[bastion.example.com]:2222 ' . TunnelKeys::PINNED);
});

it('matches only the pinned key', function (): void {
    $key = HostKey::parse(TunnelKeys::PINNED);

    expect($key->matches(TunnelKeys::PINNED))->toBeTrue()
        ->and($key->matches(TunnelKeys::OTHER))->toBeFalse()
        ->and($key->matches(null))->toBeFalse()
        ->and($key->matches('garbage'))->toBeFalse();
});
