<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Drivers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Enums\VaultAuthMethod;
use Simtabi\Laranail\DBConsole\Exceptions\SecretDriverMisconfigured;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Throwable;

/**
 * The secret lives in HashiCorp Vault (KV v2); the catalog holds nothing
 * decryptable. DBConsole reads it at use-time over Vault's HTTP API,
 * authenticating with AppRole or a token. Reading the catalog yields only
 * the Vault path, useless without a live, separately-authenticated call.
 *
 * Uses Laravel's HTTP client (already a dependency) — no external SDK.
 *
 * @param array{
 *     address: ?string, auth: string, role_id: ?string, secret_id: ?string,
 *     token: ?string, mount: string, path_prefix: string
 * } $config
 */
final class HashiCorpVault implements SecretVault
{
    private ?string $cachedToken = null;

    /**
     * @param array{
     *     address: ?string, auth: string, role_id: ?string, secret_id: ?string,
     *     token: ?string, mount: string, path_prefix: string
     * } $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    public function store(string $ref, Secret $secret): void
    {
        $this->write($this->path($ref), ['value' => $secret->reveal()]);
    }

    public function reveal(string $ref): Secret
    {
        try {
            $data = $this->read($this->path($ref));
        } catch (SecretDriverMisconfigured $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference($ref, $this->driver(), $e);
        }

        $value = $data['value'] ?? null;
        if (! is_string($value)) {
            throw SecretUnavailable::forReference($ref, $this->driver());
        }

        return new Secret($value);
    }

    public function forget(string $ref): void
    {
        $this->request()->delete($this->metadataUrl($this->path($ref)));
    }

    public function rotate(string $ref, Secret $new): void
    {
        // KV v2 versions automatically; writing a new value creates a new version.
        $this->store($ref, $new);
    }

    public function driver(): string
    {
        return SecretDriver::Vault->value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $path, array $data): void
    {
        $response = $this->request()->post($this->dataUrl($path), ['data' => $data]);

        if ($response->failed()) {
            throw SecretUnavailable::forReference($path, $this->driver());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        $response = $this->request()->get($this->dataUrl($path));

        if ($response->failed()) {
            throw SecretUnavailable::forReference($path, $this->driver());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data.data', []);

        return $data;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->address())
            ->withHeader('X-Vault-Token', $this->token())
            ->acceptJson()
            ->asJson();
    }

    private function token(): string
    {
        $method = VaultAuthMethod::tryFrom($this->config['auth'] ?? 'approle') ?? VaultAuthMethod::AppRole;

        if ($method === VaultAuthMethod::Token) {
            $token = $this->config['token'] ?? null;
            if (! is_string($token) || $token === '') {
                throw SecretDriverMisconfigured::forDriver('vault', 'token auth selected but no DB_CONSOLE_VAULT_TOKEN set');
            }

            return $token;
        }

        return $this->cachedToken ??= $this->loginWithAppRole();
    }

    private function loginWithAppRole(): string
    {
        $roleId = $this->config['role_id'] ?? null;
        $secretId = $this->config['secret_id'] ?? null;
        if (! is_string($roleId) || ! is_string($secretId) || $roleId === '' || $secretId === '') {
            throw SecretDriverMisconfigured::forDriver('vault', 'AppRole auth needs role_id and secret_id');
        }

        $response = $this->http
            ->baseUrl($this->address())
            ->acceptJson()
            ->asJson()
            ->post('/v1/auth/approle/login', ['role_id' => $roleId, 'secret_id' => $secretId]);

        $token = $response->json('auth.client_token');
        if (! is_string($token) || $token === '') {
            throw SecretDriverMisconfigured::forDriver('vault', 'AppRole login did not return a client token');
        }

        return $token;
    }

    private function address(): string
    {
        $address = $this->config['address'] ?? null;
        if (! is_string($address) || $address === '') {
            throw SecretDriverMisconfigured::forDriver('vault', 'no Vault address configured (DB_CONSOLE_VAULT_ADDR)');
        }

        return rtrim($address, '/');
    }

    private function path(string $ref): string
    {
        return trim($this->config['path_prefix'] ?? 'db-console', '/').'/'.ltrim($ref, '/');
    }

    private function dataUrl(string $path): string
    {
        return '/v1/'.$this->mount().'/data/'.$path;
    }

    private function metadataUrl(string $path): string
    {
        return '/v1/'.$this->mount().'/metadata/'.$path;
    }

    private function mount(): string
    {
        return trim($this->config['mount'] ?? 'secret', '/');
    }
}
