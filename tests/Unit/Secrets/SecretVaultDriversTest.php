<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;
use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Exceptions\SecretDriverMisconfigured;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\KmsClient;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\ReferenceResolver;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\AppKeyVault;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\KmsVault;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\ReferenceVault;
use Simtabi\Laranail\DBConsole\Secrets\Kms\AwsKmsClient;
use Simtabi\Laranail\DBConsole\Secrets\Kms\GcpKmsClient;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\Stores\ArraySecretStore;

const ADMIN_SECRET = 'sup3r-s3cret-admin-p@ssw0rd-value';

function appKeyVault(ArraySecretStore $store): AppKeyVault
{
    return new AppKeyVault(new Encrypter(random_bytes(32), 'aes-256-gcm'), $store);
}

/** A fake KMS: "wraps" by prefixing, so the wrapped blob is not the data key. */
function fakeKms(): KmsClient
{
    return new class implements KmsClient
    {
        public function wrap(string $plaintextDataKey): string
        {
            return 'WRAPPED::'.base64_encode($plaintextDataKey);
        }

        public function unwrap(string $wrappedDataKey): string
        {
            return (string) base64_decode(substr($wrappedDataKey, strlen('WRAPPED::')), true);
        }

        public function provider(): string
        {
            return 'fake';
        }
    };
}

describe('app_key driver', function (): void {
    it('round-trips a secret and reports its driver', function (): void {
        $store = new ArraySecretStore;
        $vault = appKeyVault($store);

        $vault->store('server:prod', new Secret(ADMIN_SECRET));

        expect($vault->reveal('server:prod')->reveal())->toBe(ADMIN_SECRET)
            ->and($vault->driver())->toBe(SecretDriver::AppKey->value);
    });

    it('stores ciphertext, never the plaintext', function (): void {
        $store = new ArraySecretStore;
        appKeyVault($store)->store('server:prod', new Secret(ADMIN_SECRET));

        expect($store->get('server:prod'))->not->toContain(ADMIN_SECRET);
    });

    it('rotates by re-encrypting', function (): void {
        $store = new ArraySecretStore;
        $vault = appKeyVault($store);
        $vault->store('server:prod', new Secret(ADMIN_SECRET));
        $vault->rotate('server:prod', new Secret('a-completely-different-secret-9'));

        expect($vault->reveal('server:prod')->reveal())->toBe('a-completely-different-secret-9');
    });

    it('raises SecretUnavailable for an unknown handle', function (): void {
        appKeyVault(new ArraySecretStore)->reveal('server:ghost');
    })->throws(SecretUnavailable::class);
});

describe('kms driver (envelope encryption, real crypto behind a fake KMS)', function (): void {
    it('round-trips via envelope encryption', function (): void {
        $store = new ArraySecretStore;
        $vault = new KmsVault(fakeKms(), $store);

        $vault->store('server:prod', new Secret(ADMIN_SECRET));

        expect($vault->reveal('server:prod')->reveal())->toBe(ADMIN_SECRET)
            ->and($vault->driver())->toBe(SecretDriver::Kms->value);
    });

    it('stores only a wrapped data key + ciphertext — nothing plaintext-recoverable from the catalog', function (): void {
        $store = new ArraySecretStore;
        new KmsVault(fakeKms(), $store)->store('server:prod', new Secret(ADMIN_SECRET));

        $payload = (string) $store->get('server:prod');
        $envelope = json_decode($payload, true);

        expect($payload)->not->toContain(ADMIN_SECRET)
            ->and($envelope)->toHaveKeys(['wrapped_key', 'iv', 'tag', 'ciphertext'])
            // The wrapped key is NOT the usable data key: without a live
            // unwrap call the ciphertext cannot be decrypted.
            ->and(base64_decode((string) $envelope['wrapped_key'], true))->toStartWith('WRAPPED::');
    });

    it('fails to reveal if the KMS cannot unwrap (simulated key loss)', function (): void {
        $store = new ArraySecretStore;
        new KmsVault(fakeKms(), $store)->store('server:prod', new Secret(ADMIN_SECRET));

        $brokenKms = new class implements KmsClient
        {
            public function wrap(string $k): string
            {
                return $k;
            }

            public function unwrap(string $w): string
            {
                return 'wrong-key-32-bytes-length-abcdef';
            }

            public function provider(): string
            {
                return 'fake';
            }
        };

        expect(fn (): Secret => new KmsVault($brokenKms, $store)->reveal('server:prod'))
            ->toThrow(SecretUnavailable::class);
    });
});

describe('reference driver (stores only a pointer)', function (): void {
    it('resolves the pointer fresh each reveal and never persists the credential', function (): void {
        $store = new ArraySecretStore;
        $calls = 0;

        $resolver = new class($calls) implements ReferenceResolver
        {
            public function __construct(public int &$calls) {}

            public function resolve(string $pointer): Secret
            {
                $this->calls++;

                return new Secret('resolved-for-'.$pointer);
            }

            public function provider(): string
            {
                return 'fake';
            }
        };

        $vault = new ReferenceVault($resolver, $store);
        $vault->store('server:prod', new Secret('arn:aws:secretsmanager:prod-admin'));

        expect($store->get('server:prod'))->toBe('arn:aws:secretsmanager:prod-admin')
            ->and($vault->reveal('server:prod')->reveal())->toBe('resolved-for-arn:aws:secretsmanager:prod-admin')
            ->and($vault->reveal('server:prod')->reveal())->toBe('resolved-for-arn:aws:secretsmanager:prod-admin')
            ->and($calls)->toBe(2)   // fetched fresh each time, not cached
            ->and($vault->driver())->toBe(SecretDriver::Reference->value);
    });
});

describe('optional-SDK degradation and config guards', function (): void {
    it('the GCP KMS client degrades cleanly when google/cloud-kms is absent', function (): void {
        // google/cloud-kms is NOT a dependency of this repo, so the GCP
        // adapter must report a clear misconfiguration rather than fatal on
        // an unknown class — the graceful-degradation invariant for every
        // optional dependency.
        $client = new GcpKmsClient(['key_id' => 'projects/p/locations/l/keyRings/r/cryptoKeys/k', 'region' => null]);

        expect(fn (): string => $client->wrap('0123456789012345678901234567890a'))
            ->toThrow(SecretDriverMisconfigured::class);
    })->skip(class_exists('Google\\Cloud\\Kms\\V1\\KeyManagementServiceClient'), 'google/cloud-kms is installed');

    it('the AWS KMS client reports misconfiguration when no key id is configured (SDK present)', function (): void {
        $client = new AwsKmsClient(['key_id' => null, 'region' => 'us-east-1']);

        expect(fn (): string => $client->wrap('0123456789012345678901234567890a'))
            ->toThrow(SecretDriverMisconfigured::class);
    })->skip(! class_exists(Aws\Kms\KmsClient::class), 'aws/aws-sdk-php not installed');
});
