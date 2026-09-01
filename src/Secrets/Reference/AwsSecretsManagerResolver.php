<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Reference;

use Aws\SecretsManager\SecretsManagerClient;
use Simtabi\Laranail\DBConsole\Exceptions\SecretDriverMisconfigured;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\ReferenceResolver;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Throwable;

/**
 * Resolves an AWS Secrets Manager pointer (a secret name or ARN) via the
 * optional aws/aws-sdk-php package. Absent SDK degrades to
 * SecretDriverMisconfigured with the install fix.
 */
final class AwsSecretsManagerResolver implements ReferenceResolver
{
    private ?SecretsManagerClient $sdk = null;

    /**
     * @param  array{region: ?string}  $config
     */
    public function __construct(private readonly array $config = ['region' => null]) {}

    public function resolve(string $pointer): Secret
    {
        try {
            $result = $this->sdk()->getSecretValue(['SecretId' => $pointer]);
            $value = $result['SecretString'] ?? null;

            if (! is_string($value)) {
                throw SecretUnavailable::forReference($pointer, $this->provider());
            }

            return new Secret($value);
        } catch (SecretDriverMisconfigured|SecretUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference($pointer, $this->provider(), $e);
        }
    }

    public function provider(): string
    {
        return 'aws-secrets-manager';
    }

    private function sdk(): SecretsManagerClient
    {
        if ($this->sdk instanceof SecretsManagerClient) {
            return $this->sdk;
        }

        if (! class_exists(SecretsManagerClient::class)) {
            throw SecretDriverMisconfigured::forDriver(
                'reference',
                'the AWS Secrets Manager resolver needs the aws/aws-sdk-php package (composer require aws/aws-sdk-php)',
            );
        }

        $args = ['version' => 'latest'];
        if (($this->config['region'] ?? null) !== null) {
            $args['region'] = $this->config['region'];
        }

        return $this->sdk = new SecretsManagerClient($args);
    }
}
