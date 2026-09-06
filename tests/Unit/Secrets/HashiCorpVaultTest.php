<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Illuminate\Http\Client\Factory as HttpFactory;
use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\HashiCorpVault;
use Simtabi\Laranail\DBConsole\Exceptions\SecretDriverMisconfigured;

/**
 * @param array<string, mixed> $overrides
 *
 * @return array{address: ?string, auth: string, role_id: ?string, secret_id: ?string, token: ?string, mount: string, path_prefix: string}
 */
function vaultConfig(array $overrides = []): array
{
    return array_merge([
        'address'     => 'https://vault.example.com',
        'auth'        => 'token',
        'role_id'     => null,
        'secret_id'   => null,
        'token'       => 'root-token',
        'mount'       => 'secret',
        'path_prefix' => 'db-console',
    ], $overrides);
}

it('reads a KV v2 secret over the Vault HTTP API with a token', function (): void {
    Http::fake([
        'https://vault.example.com/v1/secret/data/db-console/server:prod' => Http::response([
            'data' => ['data' => ['value' => 'vault-stored-admin-secret']],
        ]),
    ]);

    $vault = new HashiCorpVault(app(HttpFactory::class), vaultConfig());

    expect($vault->reveal('server:prod')->reveal())->toBe('vault-stored-admin-secret')
        ->and($vault->driver())->toBe(SecretDriver::Vault->value);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Vault-Token', 'root-token')
        && str_contains((string) $request->url(), '/v1/secret/data/db-console/server:prod'));
});

it('writes a KV v2 secret', function (): void {
    Http::fake(['https://vault.example.com/*' => Http::response([], 200)]);

    new HashiCorpVault(app(HttpFactory::class), vaultConfig())
        ->store('server:prod', new Secret('new-admin-secret'));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->url(), '/v1/secret/data/db-console/server:prod')
        && $request['data']['value'] === 'new-admin-secret');
});

it('authenticates with AppRole when configured, then uses the returned token', function (): void {
    Http::fake([
        'https://vault.example.com/v1/auth/approle/login' => Http::response([
            'auth' => ['client_token' => 'approle-issued-token'],
        ]),
        'https://vault.example.com/v1/secret/data/*' => Http::response([
            'data' => ['data' => ['value' => 'secret-via-approle']],
        ]),
    ]);

    $vault = new HashiCorpVault(app(HttpFactory::class), vaultConfig([
        'auth' => 'approle', 'token' => null, 'role_id' => 'r', 'secret_id' => 's',
    ]));

    expect($vault->reveal('server:prod')->reveal())->toBe('secret-via-approle');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/auth/approle/login'));
});

it('reports misconfiguration when AppRole credentials are missing', function (): void {
    $vault = new HashiCorpVault(app(HttpFactory::class), vaultConfig([
        'auth' => 'approle', 'token' => null, 'role_id' => null, 'secret_id' => null,
    ]));

    expect(fn (): Secret => $vault->reveal('server:prod'))->toThrow(SecretDriverMisconfigured::class);
});

it('reports misconfiguration when no Vault address is set', function (): void {
    $vault = new HashiCorpVault(app(HttpFactory::class), vaultConfig(['address' => null]));

    expect(fn (): Secret => $vault->reveal('server:prod'))->toThrow(SecretDriverMisconfigured::class);
});
