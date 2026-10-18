<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

/**
 * Two throwaway ed25519 public keys: one pinned, one presented by an
 * impostor. Public halves only; nothing here authenticates anywhere.
 */
final class TunnelKeys
{
    public const string PINNED = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINhPMVpDM4Rag4mH54u7tQiLouDwLRICYFI63kWc3NV6';

    public const string PINNED_FINGERPRINT = 'SHA256:6OedZyvDDha3pwPzF05wnnWi7JR0j3xRF2sks+cQlSA';

    public const string OTHER = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIAnaEumkY7TnM0aaTjL5esLFFFPLwHmvBXrwG/GB6m1';

    /**
     * A fresh ed25519 public key line, generated at run time for a test that
     * needs a well-formed host key but no particular one. The constants above
     * stay fixed because HostKeyTest proves their fingerprint exactly.
     */
    public static function generated(): string
    {
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $blob = pack('N', 11) . 'ssh-ed25519' . pack('N', strlen($public)) . $public;

        return 'ssh-ed25519 ' . base64_encode($blob);
    }
}
