<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

use Stringable;
use JsonSerializable;
use SensitiveParameter;
use Simtabi\Laranail\DBConsole\Exceptions\WeakPassword;
use Simtabi\Laranail\DBConsole\Domain\Concerns\RedactsSelf;

/**
 * An account password: strength-checked on construction, self-redacting on
 * every accidental exposure path. Only reveal() returns the real value —
 * and only engines (building CREATE USER / SET PASSWORD statements) and
 * the show-once surfaces call it.
 *
 * The hard floor here is construction-time; the configurable policy
 * (accounts.password_min_length) is applied by PasswordStrengthRule on top.
 */
final class Password implements JsonSerializable, Stringable
{
    use RedactsSelf;

    public const int DEFAULT_MIN_LENGTH = 16;

    private const string REDACTED = '[redacted]';

    private const string SYMBOLS = '!#$%&()*+,-./:;<=>?@[]^_{|}~';

    private string $value;

    public function __construct(#[SensitiveParameter] string $value, int $minLength = self::DEFAULT_MIN_LENGTH)
    {
        $length = strlen($value);
        if ($length < $minLength) {
            throw WeakPassword::because("shorter than {$minLength} characters", $minLength);
        }

        if ($this->characterClassCount($value) < 3) {
            throw WeakPassword::because(
                'must mix at least three of: lowercase, uppercase, digits, symbols',
                $minLength,
            );
        }

        $this->value = $value;
    }

    /**
     * Generate a cryptographically random password containing all four
     * character classes.
     */
    public static function generate(int $length = 24): self
    {
        $length = max($length, self::DEFAULT_MIN_LENGTH);

        $pools = [
            'abcdefghijklmnopqrstuvwxyz',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '0123456789',
            self::SYMBOLS,
        ];

        $characters = [];
        foreach ($pools as $pool) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        $all = implode('', $pools);
        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher-Yates with a CSPRNG; str_shuffle is not cryptographically safe.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return new self(implode('', $characters));
    }

    /**
     * The only accessor for the real value.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    private function characterClassCount(#[SensitiveParameter] string $value): int
    {
        $classes = 0;
        $classes += preg_match('/[a-z]/', $value) === 1 ? 1 : 0;
        $classes += preg_match('/[A-Z]/', $value) === 1 ? 1 : 0;
        $classes += preg_match('/[0-9]/', $value) === 1 ? 1 : 0;
        $classes += preg_match('/[^a-zA-Z0-9]/', $value) === 1 ? 1 : 0;

        return $classes;
    }
}
