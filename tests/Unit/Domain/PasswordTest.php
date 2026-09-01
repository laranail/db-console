<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Exceptions\WeakPassword;
use Simtabi\Laranail\DBConsole\Secrets\Secret;

const STRONG_SAMPLE = 'Xk9$mQ2vLpW7#nR4t!';

describe('strength policy', function (): void {
    it('rejects a password shorter than the minimum', function (): void {
        new Password('Ab1!short');
    })->throws(WeakPassword::class);

    it('rejects a long password with too few character classes', function (): void {
        new Password('alllowercaseletters');
    })->throws(WeakPassword::class);

    it('honors a caller-supplied higher minimum', function (): void {
        new Password(STRONG_SAMPLE, 32);
    })->throws(WeakPassword::class);

    it('accepts a strong password and reveals it only explicitly', function (): void {
        $password = new Password(STRONG_SAMPLE);

        expect($password->reveal())->toBe(STRONG_SAMPLE);
    });
});

describe('redaction (must never be weakened)', function (): void {
    it('redacts on string interpolation', function (): void {
        $password = new Password(STRONG_SAMPLE);

        expect("value: {$password}")->toBe('value: [redacted]')
            ->and((string) $password)->not->toContain(STRONG_SAMPLE);
    });

    it('redacts in json_encode', function (): void {
        $password = new Password(STRONG_SAMPLE);

        expect(json_encode(['password' => $password]))->not->toContain(STRONG_SAMPLE)
            ->and(json_encode($password))->toContain('redacted');
    });

    it('redacts in var_dump via __debugInfo', function (): void {
        $password = new Password(STRONG_SAMPLE);

        ob_start();
        var_dump($password);
        $dump = ob_get_clean();

        expect($dump)->not->toContain(STRONG_SAMPLE)
            ->and($dump)->toContain('redacted');
    });

    it('does not survive serialization', function (): void {
        $password = new Password(STRONG_SAMPLE);

        $serialized = serialize($password);

        expect($serialized)->not->toContain(STRONG_SAMPLE);

        $revived = unserialize($serialized);
        expect($revived->reveal())->toBe('[redacted]');
    });

    it('never leaks the candidate value through the WeakPassword exception', function (): void {
        $weak = 'MyRecognizable1!';   // 16 chars but we force a higher minimum

        try {
            new Password($weak, 20);
            $this->fail('expected WeakPassword');
        } catch (WeakPassword $e) {
            expect($e->getMessage())->not->toContain($weak)
                ->and($e->userMessage())->not->toContain($weak)
                ->and(json_encode($e->context()))->not->toContain($weak);
        }
    });
});

describe('generation', function (): void {
    it('generates passwords that satisfy the constructor', function (): void {
        $password = Password::generate();

        expect(strlen($password->reveal()))->toBe(24);
    });

    it('never enlarges below the floor and produces distinct values', function (): void {
        $a = Password::generate(4);   // clamped up to the floor
        $b = Password::generate(4);

        expect(strlen($a->reveal()))->toBeGreaterThanOrEqual(Password::DEFAULT_MIN_LENGTH)
            ->and($a->reveal())->not->toBe($b->reveal());
    });
});

describe('Secret redaction', function (): void {
    it('redacts everywhere and reveals only explicitly', function (): void {
        $secret = new Secret('super-secret-material');

        expect((string) $secret)->toBe('[redacted]')
            ->and(json_encode(['s' => $secret]))->not->toContain('super-secret-material')
            ->and(serialize($secret))->not->toContain('super-secret-material')
            ->and($secret->reveal())->toBe('super-secret-material');
    });

    it('generates URL-safe random material', function (): void {
        $secret = Secret::generate();

        expect($secret->reveal())->toMatch('/^[A-Za-z0-9_\-]{40,}$/')
            ->and(Secret::generate()->reveal())->not->toBe($secret->reveal());
    });
});
