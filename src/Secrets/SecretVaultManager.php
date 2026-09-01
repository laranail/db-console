<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Simtabi\Laranail\DBConsole\Enums\KmsProvider;
use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Exceptions\InsecureSecretDriver;
use Simtabi\Laranail\DBConsole\Exceptions\SecretDriverMisconfigured;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\ReferenceResolver;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\SecretStore;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\AppKeyVault;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\HashiCorpVault;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\KmsVault;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\ReferenceVault;
use Simtabi\Laranail\DBConsole\Secrets\Kms\AwsKmsClient;
use Simtabi\Laranail\DBConsole\Secrets\Kms\GcpKmsClient;
use Simtabi\Laranail\DBConsole\Secrets\Reference\AwsSecretsManagerResolver;
use Simtabi\Laranail\DBConsole\Secrets\Reference\DopplerResolver;
use Simtabi\Laranail\DBConsole\Secrets\Reference\VaultReferenceResolver;

/**
 * Resolves the active SecretVault from config, and enforces the one
 * boot-time safety rule: app_key is refused in production without the
 * explicit override, so a real deployment can never protect its keyring
 * with a key sitting next to the ciphertext by accident (section 8).
 */
final readonly class SecretVaultManager
{
    public function __construct(
        private Container $container,
        private Config $config,
        private SecretStore $store,
    ) {}

    /**
     * The single guard called at boot/install: throws InsecureSecretDriver
     * BEFORE any request is served if app_key is selected in production
     * without allow_app_key_in_production=true.
     */
    public function assertSecureForEnvironment(): void
    {
        if ($this->driverName() !== SecretDriver::AppKey) {
            return;
        }

        $app = $this->container instanceof Application ? $this->container : null;
        $isProduction = $app?->environment('production') ?? false;

        $override = (bool) $this->config->get('laranail.db-console.secrets.allow_app_key_in_production', false);

        if ($isProduction && ! $override) {
            throw InsecureSecretDriver::appKeyInProduction();
        }
    }

    public function make(): SecretVault
    {
        return match ($this->driverName()) {
            SecretDriver::AppKey => new AppKeyVault(
                $this->container->make(Encrypter::class),
                $this->store,
            ),
            SecretDriver::Kms => new KmsVault($this->makeKmsClient(), $this->store),
            SecretDriver::Vault => $this->makeHashiCorpVault(),
            SecretDriver::Reference => new ReferenceVault($this->makeReferenceResolver(), $this->store),
        };
    }

    public function driverName(): SecretDriver
    {
        $value = (string) $this->config->get('laranail.db-console.secrets.driver', SecretDriver::AppKey->value);

        return SecretDriver::tryFrom($value)
            ?? throw SecretDriverMisconfigured::forDriver($value, 'unknown secret driver; expected one of: '
                .implode(', ', SecretDriver::values()));
    }

    private function makeKmsClient(): AwsKmsClient|GcpKmsClient
    {
        /** @var array{provider?: string, key_id?: ?string, region?: ?string} $kms */
        $kms = (array) $this->config->get('laranail.db-console.secrets.kms', []);
        $provider = KmsProvider::tryFrom((string) ($kms['provider'] ?? 'aws')) ?? KmsProvider::Aws;
        $clientConfig = ['key_id' => $kms['key_id'] ?? null, 'region' => $kms['region'] ?? null];

        return match ($provider) {
            KmsProvider::Aws => new AwsKmsClient($clientConfig),
            KmsProvider::Gcp => new GcpKmsClient($clientConfig),
        };
    }

    private function makeHashiCorpVault(): HashiCorpVault
    {
        /** @var array<string, mixed> $vault */
        $vault = (array) $this->config->get('laranail.db-console.secrets.vault', []);

        return new HashiCorpVault($this->container->make(HttpFactory::class), $this->vaultConfig($vault));
    }

    private function makeReferenceResolver(): ReferenceResolver
    {
        /** @var array{provider?: string} $reference */
        $reference = (array) $this->config->get('laranail.db-console.secrets.reference', []);
        $provider = (string) ($reference['provider'] ?? 'aws-secrets-manager');

        /** @var array{region?: ?string} $kms */
        $kms = (array) $this->config->get('laranail.db-console.secrets.kms', []);

        return match ($provider) {
            'vault' => new VaultReferenceResolver($this->makeHashiCorpVault()),
            'doppler' => new DopplerResolver(
                $this->container->make(HttpFactory::class),
                ['token' => $this->config->get('laranail.db-console.secrets.reference.token')],
            ),
            default => new AwsSecretsManagerResolver(['region' => $kms['region'] ?? null]),
        };
    }

    /**
     * @param  array<string, mixed>  $vault
     * @return array{address: ?string, auth: string, role_id: ?string, secret_id: ?string, token: ?string, mount: string, path_prefix: string}
     */
    private function vaultConfig(array $vault): array
    {
        return [
            'address' => isset($vault['address']) ? (string) $vault['address'] : null,
            'auth' => (string) ($vault['auth'] ?? 'approle'),
            'role_id' => isset($vault['role_id']) ? (string) $vault['role_id'] : null,
            'secret_id' => isset($vault['secret_id']) ? (string) $vault['secret_id'] : null,
            'token' => isset($vault['token']) ? (string) $vault['token'] : null,
            'mount' => (string) ($vault['mount'] ?? 'secret'),
            'path_prefix' => (string) ($vault['path_prefix'] ?? 'db-console'),
        ];
    }
}
