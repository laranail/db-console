<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Kms\KmsClient as AwsKms;
use Aws\MockHandler;
use Aws\Result;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\KmsVault;
use Simtabi\Laranail\DBConsole\Secrets\Kms\AwsKmsClient;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\Stores\ArraySecretStore;

/*
 * A real integration test of the AWS KMS adapter against the aws-sdk-php
 * MockHandler — the actual SDK client, no cloud credentials. This proves the
 * KmsVault envelope flow works end to end through the genuine SDK surface
 * (encrypt/decrypt result shapes), not only against the unit-test fake.
 *
 * The mock simulates KMS by base64-wrapping the data key, so the wrapped
 * blob is distinct from the plaintext key — exactly as a real KMS behaves.
 */

it('drives the full envelope round-trip through the real aws-sdk KMS client', function (): void {
    // The mock decrypt must return the SAME data key that encrypt received;
    // a shared holder carries it across the encrypt/decrypt pair, so the
    // wrapped blob stays opaque (distinct from the plaintext key).
    $holder = new stdClass;
    $holder->dataKey = null;

    $mock = new MockHandler;
    $mock->append(function (CommandInterface $cmd) use ($holder): Result {
        // encrypt: remember the plaintext, return an opaque wrapped blob
        $holder->dataKey = (string) $cmd['Plaintext'];

        return new Result(['CiphertextBlob' => 'wrapped:' . base64_encode((string) $cmd['Plaintext'])]);
    });
    $mock->append(
        // decrypt: return the remembered plaintext data key
        fn (CommandInterface $cmd): Result => new Result(['Plaintext' => $holder->dataKey]));

    $realSdk = new AwsKms([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $mock,
    ]);

    $adapter = new AwsKmsClient(['key_id' => 'alias/db-console', 'region' => 'us-east-1']);
    // Inject the mock-backed real client.
    $ref = new ReflectionProperty($adapter, 'sdk');
    $ref->setValue($adapter, $realSdk);

    $store = new ArraySecretStore;
    $vault = new KmsVault($adapter, $store);

    $vault->store('server:prod', new Secret('the-admin-credential-value-here'));

    expect($store->get('server:prod'))->not->toContain('the-admin-credential-value-here')
        ->and($vault->reveal('server:prod')->reveal())->toBe('the-admin-credential-value-here');
})->skip(! class_exists(AwsKms::class), 'aws/aws-sdk-php not installed');
