<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Normalizer;
use SensitiveParameter;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Exceptions\WeakPassword;

/**
 * A PostgreSQL SCRAM-SHA-256 password verifier, built client-side (RFC 5802
 * and RFC 7677), in the form Postgres stores in pg_authid.rolpassword:
 *
 *     SCRAM-SHA-256$<iterations>:<salt>$<StoredKey>:<ServerKey>
 *
 * Sending this instead of the password keeps the password out of the
 * statement text, which log_statement = 'ddl' and a failed statement
 * (log_min_error_statement) write to the server log. Postgres recognises the
 * form and stores it as given; the password cannot be recovered from it.
 */
final class ScramSha256
{
    public const int ITERATIONS = 4096;

    /**
     * Characters SASLprep maps to a space (RFC 3454 C.1.2) or to nothing
     * (B.1). Their presence means SASLprep would change the password.
     */
    private const string MAPPED = '/[\x{00A0}\x{1680}\x{2000}-\x{200D}\x{202F}\x{205F}\x{2060}\x{3000}'
        . '\x{00AD}\x{034F}\x{1806}\x{180B}-\x{180D}\x{FE00}-\x{FE0F}\x{FEFF}]/u';

    /**
     * @param string|null $salt raw bytes; 16 random ones when null
     * @param positive-int $iterations
     */
    public static function verifier(#[SensitiveParameter] string $password, ?string $salt = null, int $iterations = self::ITERATIONS): string
    {
        $salt ??= random_bytes(16);
        $salted = hash_pbkdf2('sha256', self::prepared($password), $salt, $iterations, 32, true);
        $storedKey = hash('sha256', hash_hmac('sha256', 'Client Key', $salted, true), true);
        $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);

        return 'SCRAM-SHA-256$' . $iterations . ':' . base64_encode($salt)
            . '$' . base64_encode($storedKey) . ':' . base64_encode($serverKey);
    }

    /**
     * The bytes Postgres hashes: the password after SASLprep, or the raw
     * password when SASLprep refuses it. Both are the raw bytes whenever
     * SASLprep would leave the password unchanged: when it is ASCII (which
     * Postgres passes through untouched), not valid UTF-8 (refused), or
     * neither mapped nor changed by NFKC (then success and refusal agree).
     *
     * Anything else depends on whether the normalised form passes
     * SASLprep's prohibited, bidirectional and unassigned-code-point checks,
     * which need Unicode 3.2 tables this does not carry. Guessing wrong
     * stores a verifier no login matches, so it refuses instead.
     */
    private static function prepared(#[SensitiveParameter] string $password): string
    {
        if (preg_match('/^[\x00-\x7F]*$/', $password) === 1 || ! mb_check_encoding($password, 'UTF-8')) {
            return $password;
        }

        $normalized = class_exists(Normalizer::class) ? Normalizer::normalize($password, Normalizer::FORM_KC) : false;

        if (preg_match(self::MAPPED, $password) === 1 || $normalized !== $password) {
            throw WeakPassword::because(
                'contains characters PostgreSQL would rewrite before hashing (SASLprep); use a password already in NFKC form, without non-ASCII spaces or soft hyphens',
                Password::DEFAULT_MIN_LENGTH,
            );
        }

        return $password;
    }
}
