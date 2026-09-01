<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\IdentifierRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\PasswordStrengthRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\PrivilegeRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\ScopeRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;

/**
 * @param  list<mixed>  $rules
 * @return list<string> validation messages (empty when valid)
 */
function validateField(mixed $value, array $rules): array
{
    $validator = Validator::make(['field' => $value], ['field' => $rules]);

    return $validator->errors()->get('field');
}

describe('rules and value objects share one definition', function (): void {
    it('IdentifierRule rejects exactly what the DbName constructor rejects — with the identical message', function (string $input): void {
        $voMessage = null;
        try {
            new DbName($input);
        } catch (DBConsoleException $e) {
            $voMessage = $e->userMessage();
        }

        $ruleMessages = validateField($input, [new IdentifierRule]);

        expect($voMessage)->not->toBeNull()
            ->and($ruleMessages)->toContain($voMessage);
    })->with(['shop`db', "shop'db", 'shop;DROP TABLE users', 'shоp', 'mysql.user']);

    // Laravel only invokes non-implicit rules on present values: an empty
    // string is the 'required' rule's job at the form layer, and the value
    // object still rejects it at the domain layer (proven below).
    it('the constructor rejects the empty string even though form-layer emptiness is handled by required', function (): void {
        expect(fn (): DbName => new DbName(''))->toThrow(InvalidIdentifier::class);
    });

    it('accepts what the constructor accepts', function (): void {
        expect(validateField('shop_prod', [new IdentifierRule]))->toBe([])
            ->and(validateField('shop_user', [new UsernameRule]))->toBe([])
            ->and(validateField('10.0.%', [new HostRule]))->toBe([]);
    });

    it('UsernameRule and HostRule reject hostile input', function (): void {
        expect(validateField('user@host', [new UsernameRule]))->not->toBe([])
            ->and(validateField("10.0.0.1'", [new HostRule]))->not->toBe([]);
    });
});

describe('password policy flows from config', function (): void {
    it('applies the configured minimum on top of the hard floor', function (): void {
        config()->set('laranail.db-console.accounts.password_min_length', 20);

        $eighteen = 'Xk9$mQ2vLpW7#nR4t!';

        expect(strlen($eighteen))->toBe(18)
            ->and(validateField($eighteen, [new PasswordStrengthRule]))->not->toBe([]);
    });

    it('never validates below the hard floor even if config is loosened', function (): void {
        config()->set('laranail.db-console.accounts.password_min_length', 4);

        expect(validateField('Ab1!x', [new PasswordStrengthRule]))->not->toBe([]);
    });

    it('accepts a strong password at the default policy', function (): void {
        config()->set('laranail.db-console.accounts.password_min_length', 16);

        expect(validateField('Xk9$mQ2vLpW7#nR4t!', [new PasswordStrengthRule]))->toBe([]);
    });
});

describe('privilege rule mirrors the domain guard', function (): void {
    it('rejects forbidden privileges with the forbidden message', function (): void {
        $messages = validateField('GRANT OPTION', [new PrivilegeRule]);

        expect($messages)->toHaveCount(1)
            ->and($messages[0])->toContain('never be granted');
    });

    it('rejects unknown privileges as unknown', function (): void {
        $messages = validateField('FLY', [new PrivilegeRule]);

        expect($messages[0])->toContain('not on the allow-list');
    });

    it('accepts allow-listed privileges', function (): void {
        expect(validateField('select', [new PrivilegeRule]))->toBe([])
            ->and(validateField('CREATE VIEW', [new PrivilegeRule]))->toBe([]);
    });
});

describe('scope rule', function (): void {
    it('accepts well-formed scopes', function (string $scope): void {
        expect(validateField($scope, [new ScopeRule]))->toBe([]);
    })->with(['global', 'server:prod-mysql', 'database:prod-mysql/shop_prod', 'database:prod-mysql/shop_*']);

    it('rejects malformed scopes', function (string $scope): void {
        expect(validateField($scope, [new ScopeRule]))->not->toBe([]);
    })->with([
        'everything', 'server:', 'server:bad name', 'database:prod-mysql',
        'database:prod-mysql/shop/extra', 'database:prod-mysql/*',
        'global:prod', "server:prod'; DROP",
    ]);
});

it('validation is defense-in-depth: bypassing every rule, the constructor still rejects', function (): void {
    // No FormRequest, no Validator — straight to the domain.
    expect(fn (): DbName => new DbName('shop;DROP TABLE users'))
        ->toThrow(InvalidIdentifier::class);
});
