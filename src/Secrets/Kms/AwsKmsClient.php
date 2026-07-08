<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Kms;

use Aws\Kms\KmsClient as AwsKms;
use Simtabi\Laranail\DBConsole\Enums\KmsProvider;
use Simtabi\Laranail\DBConsole\Exceptions\SecretDriverMisconfigured;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\KmsClient;
use Throwable;

/**
 * AWS KMS wrap/unwrap over the optional aws/aws-sdk-php package. When the
 * SDK is absent this driver reports SecretDriverMisconfigured with the
 * install fix, rather than fataling on an unknown class — matching the
 * package's graceful-degradation stance for every optional dependency.
 */
final class AwsKmsClient implements KmsClient
{
    private ?AwsKms $sdk = null;

    /**
     * @param  array{key_id: ?string, region: ?string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function wrap(string $plaintextDataKey): string
    {
        $result = $this->sdk()->encrypt([
            'KeyId' => $this->keyId(),
            'Plaintext' => $plaintextDataKey,
        ]);

        return (string) $result['CiphertextBlob'];
    }

    public function unwrap(string $wrappedDataKey): string
    {
        try {
            $result = $this->sdk()->decrypt(['CiphertextBlob' => $wrappedDataKey]);

            return (string) $result['Plaintext'];
        } catch (SecretDriverMisconfigured $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference('kms:data-key', $this->provider(), $e);
        }
    }

    public function provider(): string
    {
        return KmsProvider::Aws->value;
    }

    private function sdk(): AwsKms
    {
        if ($this->sdk instanceof AwsKms) {
            return $this->sdk;
        }

        if (! class_exists(AwsKms::class)) {
            throw SecretDriverMisconfigured::forDriver(
                'kms',
                'the AWS KMS driver needs the aws/aws-sdk-php package (composer require aws/aws-sdk-php)',
            );
        }

        $args = ['version' => 'latest'];
        if (($this->config['region'] ?? null) !== null) {
            $args['region'] = $this->config['region'];
        }

        return $this->sdk = new AwsKms($args);
    }

    private function keyId(): string
    {
        $keyId = $this->config['key_id'] ?? null;
        if ($keyId === null || $keyId === '') {
            throw SecretDriverMisconfigured::forDriver('kms', 'no KMS key id configured (DB_CONSOLE_KMS_KEY_ID)');
        }

        return $keyId;
    }
}
