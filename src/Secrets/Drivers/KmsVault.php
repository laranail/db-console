<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Drivers;

use Throwable;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\KmsClient;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\SecretStore;

/**
 * Envelope encryption. A fresh 256-bit data key encrypts the secret locally
 * with AES-256-GCM; the data key itself is wrapped by an external KMS and
 * only the wrapped key + ciphertext are stored. Decrypting requires a live
 * KMS unwrap call (its own auth + audit), so reading the catalog — or a
 * catalog backup — yields nothing usable.
 *
 * The wrap/unwrap of the data key is the ONLY thing delegated to the KMS;
 * all cryptography here is local and fully testable with a fake KmsClient.
 */
final readonly class KmsVault implements SecretVault
{
    private const string CIPHER = 'aes-256-gcm';

    public function __construct(
        private KmsClient $kms,
        private SecretStore $store,
    ) {}

    public function store(string $ref, Secret $secret): void
    {
        $dataKey = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $secret->reveal(),
            self::CIPHER,
            $dataKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($ciphertext === false) {
            throw SecretUnavailable::forReference($ref, $this->driver());
        }

        $envelope = [
            'v'           => 1,
            'provider'    => $this->kms->provider(),
            'wrapped_key' => base64_encode($this->kms->wrap($dataKey)),
            'iv'          => base64_encode($iv),
            'tag'         => base64_encode($tag),
            'ciphertext'  => base64_encode($ciphertext),
        ];

        $this->store->put($ref, (string) json_encode($envelope));
    }

    public function reveal(string $ref): Secret
    {
        $payload = $this->store->get($ref);
        if ($payload === null) {
            throw SecretUnavailable::forReference($ref, $this->driver());
        }

        try {
            /** @var array{wrapped_key: string, iv: string, tag: string, ciphertext: string} $envelope */
            $envelope = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            $dataKey = $this->kms->unwrap(base64_decode($envelope['wrapped_key'], true) ?: '');

            $plaintext = openssl_decrypt(
                base64_decode($envelope['ciphertext'], true) ?: '',
                self::CIPHER,
                $dataKey,
                OPENSSL_RAW_DATA,
                base64_decode($envelope['iv'], true) ?: '',
                base64_decode($envelope['tag'], true) ?: '',
            );

            if ($plaintext === false) {
                throw SecretUnavailable::forReference($ref, $this->driver());
            }

            return new Secret($plaintext);
        } catch (SecretUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference($ref, $this->driver(), $e);
        }
    }

    public function forget(string $ref): void
    {
        $this->store->forget($ref);
    }

    public function rotate(string $ref, Secret $new): void
    {
        // Re-wrap under a new data key (and whatever KMS key version the
        // client is configured with); overwrite the envelope.
        $this->store($ref, $new);
    }

    public function driver(): string
    {
        return SecretDriver::Kms->value;
    }
}
