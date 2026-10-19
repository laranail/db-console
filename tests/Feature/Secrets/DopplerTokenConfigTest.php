<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\SecretVaultManager;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;

/*
 * SecretVaultManager hands the Doppler resolver secrets.reference.token. The
 * key was read but never defined, so a published config gave the resolver
 * null and every Doppler lookup failed as misconfigured.
 */

beforeEach(function (): void {
    $this->migrateCatalog();
});

it('defines secrets.reference.token in the shipped config', function (): void {
    /** @var array{secrets: array{reference: array<string, mixed>}} $shipped */
    $shipped = require dirname(__DIR__, 3) . '/config/db-console.php';

    expect($shipped['secrets']['reference'])->toHaveKey('token');
});

it('passes secrets.reference.token to the Doppler resolver', function (): void {
    config()->set('laranail.db-console.secrets.driver', 'reference');
    // @credential-literal-exempt: a provider name under the secrets key, not a credential
    config()->set('laranail.db-console.secrets.reference.provider', 'doppler');
    // Doppler service tokens carry a `dp.st.` prefix.
    $token = sprintf('dp.st.%s', TestCredentials::secret('doppler'));
    config()->set('laranail.db-console.secrets.reference.token', $token);

    Http::fake([
        'api.doppler.com/*' => Http::response(['value' => ['computed' => 'resolved-admin-secret']]),
    ]);

    $vault = app(SecretVaultManager::class)->make();
    $vault->store('server:doppler', new Secret('project/prd/DB_ADMIN'));

    expect($vault->reveal('server:doppler')->reveal())->toBe('resolved-admin-secret');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', sprintf('Bearer %s', $token))
        && str_contains($request->url(), 'name=DB_ADMIN'));
});
