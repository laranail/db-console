<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Simtabi\Laranail\DBConsole\Validation\Requests\AttachRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\CreateAccountRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\CreateDatabaseRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\DropDatabaseRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\GrantRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\RoleAssignmentRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\TokenIssueRequest;
use Simtabi\Laranail\DBConsole\Validation\Requests\WebhookRequest;
use Simtabi\Laranail\DBConsole\Validation\RuleProvider;
use Simtabi\Laranail\DBConsole\Validation\Rules\IdentifierRule;

/**
 * @param  class-string<FormRequest>  $request
 * @param  array<string, mixed>  $data
 */
function validateRequest(string $request, array $data): Illuminate\Validation\Validator
{
    return Validator::make($data, RuleProvider::for($request), RuleProvider::messages($request));
}

describe('RuleProvider is the single source of field→rules', function (): void {
    it('returns the exact rule set a FormRequest enforces', function (): void {
        $rules = RuleProvider::for(CreateDatabaseRequest::class);

        expect($rules)->toHaveKeys(['name', 'charset', 'collation'])
            ->and($rules['name'])->toContain('required')
            ->and(array_filter($rules['name'], fn (mixed $r): bool => $r instanceof IdentifierRule))
            ->not->toBe([]);
    });

    it('serves a single field for prompt/live validation', function (): void {
        expect(RuleProvider::field(CreateDatabaseRequest::class, 'name'))
            ->toEqual(RuleProvider::for(CreateDatabaseRequest::class)['name']);
    });

    it('refuses unknown fields and non-FormRequest classes', function (): void {
        expect(fn (): array => RuleProvider::field(CreateDatabaseRequest::class, 'nope'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn (): array => RuleProvider::for(stdClass::class))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('FormRequests enforce the shared rules', function (): void {
    it('CreateDatabaseRequest rejects an injection-corpus name with the value-object message', function (): void {
        $validator = validateRequest(CreateDatabaseRequest::class, ['name' => 'shop;DROP TABLE users']);

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('name'))->toContain('is not allowed');
    });

    it('CreateDatabaseRequest accepts a valid payload', function (): void {
        expect(validateRequest(CreateDatabaseRequest::class, [
            'name' => 'shop_prod', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ])->passes())->toBeTrue();
    });

    it('CreateAccountRequest requires a password unless generation is requested', function (): void {
        expect(validateRequest(CreateAccountRequest::class, [
            'username' => 'shop_user', 'host' => '10.0.%',
        ])->fails())->toBeTrue();

        expect(validateRequest(CreateAccountRequest::class, [
            'username' => 'shop_user', 'host' => '10.0.%', 'generate' => true,
        ])->passes())->toBeTrue();

        expect(validateRequest(CreateAccountRequest::class, [
            'username' => 'shop_user', 'host' => '10.0.%', 'password' => 'Xk9$mQ2vLpW7#nR4t!',
        ])->passes())->toBeTrue();
    });

    it('GrantRequest demands an explicit list for the custom preset and blocks forbidden entries', function (): void {
        $base = ['username' => 'shop_user', 'host' => '%', 'database' => 'shop_prod'];

        expect(validateRequest(GrantRequest::class, [...$base, 'preset' => 'custom'])->fails())->toBeTrue();

        expect(validateRequest(GrantRequest::class, [
            ...$base, 'preset' => 'custom', 'privileges' => ['select', 'GRANT OPTION'],
        ])->errors()->first('privileges.1'))->toContain('never be granted');

        expect(validateRequest(GrantRequest::class, [...$base, 'preset' => 'read_only'])->passes())->toBeTrue();
    });

    it('AttachRequest validates every nested pairing member', function (): void {
        $validator = validateRequest(AttachRequest::class, [
            'users' => [
                ['username' => 'svc_billing', 'host' => '10.0.%'],
                ['username' => "bad'user", 'host' => '10.0.%'],
            ],
            'databases' => ['analytics'],
            'preset' => 'read_only',
        ]);

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->has('users.1.username'))->toBeTrue();
    });

    it('DropDatabaseRequest requires typed confirmation matching the name', function (): void {
        expect(validateRequest(DropDatabaseRequest::class, [
            'name' => 'shop_prod', 'confirm' => 'shop_prod',
        ])->passes())->toBeTrue();

        $mismatch = validateRequest(DropDatabaseRequest::class, [
            'name' => 'shop_prod', 'confirm' => 'shop',
        ]);

        expect($mismatch->fails())->toBeTrue()
            ->and($mismatch->errors()->first('confirm'))->toContain('exactly');
    });

    it('RoleAssignmentRequest validates the scope expression', function (): void {
        expect(validateRequest(RoleAssignmentRequest::class, [
            'user_id' => 1, 'role' => 'admin', 'scope' => 'server:prod-mysql',
        ])->passes())->toBeTrue();

        expect(validateRequest(RoleAssignmentRequest::class, [
            'user_id' => 1, 'role' => 'admin', 'scope' => 'kingdom:everywhere',
        ])->fails())->toBeTrue();
    });

    it('WebhookRequest requires known event enums', function (): void {
        expect(validateRequest(WebhookRequest::class, [
            'url' => 'https://ops.example.com/hooks', 'events' => ['database.dropped'],
        ])->passes())->toBeTrue();

        expect(validateRequest(WebhookRequest::class, [
            'url' => 'https://ops.example.com/hooks', 'events' => ['database.exploded'],
        ])->fails())->toBeTrue();
    });

    it('TokenIssueRequest caps abilities to ConsolePermission values', function (): void {
        expect(validateRequest(TokenIssueRequest::class, [
            'user_id' => 1, 'name' => 'ci', 'abilities' => ['database.view', 'grant.create'],
        ])->passes())->toBeTrue();

        expect(validateRequest(TokenIssueRequest::class, [
            'user_id' => 1, 'name' => 'ci', 'abilities' => ['root.everything'],
        ])->fails())->toBeTrue();
    });
});
