<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Kms;

use Simtabi\Laranail\DBConsole\Enums\KmsProvider;
use Simtabi\Laranail\DBConsole\Exceptions\SecretDriverMisconfigured;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\KmsClient;
use Throwable;

/**
 * GCP Cloud KMS wrap/unwrap over the optional google/cloud-kms package.
 * Absent SDK degrades to SecretDriverMisconfigured with the install fix.
 */
final class GcpKmsClient implements KmsClient
{
    private ?object $sdk = null;

    /**
     * @param  array{key_id: ?string, region: ?string}  $config  the key_id is the full CryptoKey resource name
     */
    public function __construct(private readonly array $config) {}

    public function wrap(string $plaintextDataKey): string
    {
        $client = $this->sdk();
        $keyName = $this->keyName();

        try {
            $response = $client->encrypt($keyName, $plaintextDataKey);

            return (string) $response->getCiphertext();
        } catch (SecretDriverMisconfigured $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference('kms:data-key', $this->provider(), $e);
        }
    }

    public function unwrap(string $wrappedDataKey): string
    {
        $client = $this->sdk();
        $keyName = $this->keyName();

        try {
            $response = $client->decrypt($keyName, $wrappedDataKey);

            return (string) $response->getPlaintext();
        } catch (SecretDriverMisconfigured $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference('kms:data-key', $this->provider(), $e);
        }
    }

    public function provider(): string
    {
        return KmsProvider::Gcp->value;
    }

    private function sdk(): object
    {
        if ($this->sdk !== null) {
            return $this->sdk;
        }

        $class = 'Google\\Cloud\\Kms\\V1\\KeyManagementServiceClient';
        if (! class_exists($class)) {
            throw SecretDriverMisconfigured::forDriver(
                'kms',
                'the GCP KMS driver needs the google/cloud-kms package (composer require google/cloud-kms)',
            );
        }

        /** @var object $client */
        $client = new $class;

        return $this->sdk = $client;
    }

    private function keyName(): string
    {
        $keyName = $this->config['key_id'] ?? null;
        if ($keyName === null || $keyName === '') {
            throw SecretDriverMisconfigured::forDriver('kms', 'no KMS key resource configured (DB_CONSOLE_KMS_KEY_ID)');
        }

        return $keyName;
    }
}
