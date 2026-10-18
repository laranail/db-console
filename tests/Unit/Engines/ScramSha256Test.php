<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Engines\ScramSha256;
use Simtabi\Laranail\DBConsole\Exceptions\WeakPassword;

/*
 * The verifier is checked against the exchange in RFC 7677 section 3, not
 * against itself: the RFC's server signature and client proof can only be
 * reproduced from the right ServerKey and StoredKey.
 */

const RFC7677_SALT = 'W22ZaJ0SNY7soEsUEjb6gQ==';
const RFC7677_AUTH_MESSAGE = 'n=user,r=rOprNGfwEbeRWgbNEkqO,'
    . 'r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096,'
    . 'c=biws,r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0';

/**
 * @return array{iterations: int, salt: string, storedKey: string, serverKey: string}
 */
function scramParts(string $verifier): array
{
    expect($verifier)->toMatch('/^SCRAM-SHA-256\$(\d+):([^$]+)\$([^:]+):(.+)$/');
    preg_match('/^SCRAM-SHA-256\$(\d+):([^$]+)\$([^:]+):(.+)$/', $verifier, $m);

    return [
        'iterations' => (int) $m[1],
        'salt'       => base64_decode($m[2], true),
        'storedKey'  => base64_decode($m[3], true),
        'serverKey'  => base64_decode($m[4], true),
    ];
}

it('produces the keys of the RFC 7677 example exchange', function (): void {
    $parts = scramParts(ScramSha256::verifier('pencil', base64_decode(RFC7677_SALT, true)));

    expect($parts['iterations'])->toBe(4096)
        ->and(base64_encode($parts['salt']))->toBe(RFC7677_SALT)
        // v= in the RFC's server-final-message
        ->and(base64_encode(hash_hmac('sha256', RFC7677_AUTH_MESSAGE, $parts['serverKey'], true)))
        ->toBe('6rriTRBi23WpRR/wtup+mMhUZUn/dB5nLTJRsjl95G4=');

    // p= in the RFC's client-final-message is ClientKey XOR ClientSignature,
    // and StoredKey is H(ClientKey).
    $clientKey = base64_decode('dHzbZapWIk4jUhN+Ute9ytag9zjfMHgsqmmiz7AndVQ=', true)
        ^ hash_hmac('sha256', RFC7677_AUTH_MESSAGE, $parts['storedKey'], true);
    expect(hash('sha256', $clientKey, true))->toBe($parts['storedKey']);
});

it('salts each verifier with 16 fresh random bytes', function (): void {
    $first = scramParts(ScramSha256::verifier(testPassword()));
    $second = scramParts(ScramSha256::verifier(testPassword()));

    expect(strlen($first['salt']))->toBe(16)
        ->and($first['salt'])->not->toBe($second['salt'])
        ->and($first['storedKey'])->not->toBe($second['storedKey']);
});

// Postgres runs SASLprep on the password before hashing it, and uses the raw
// bytes when SASLprep refuses. Both give the same bytes whenever SASLprep
// would leave the password unchanged, which is what the verifier relies on.
it('hashes passwords SASLprep leaves unchanged as they are', function (string $password): void {
    $salt = random_bytes(16);
    $parts = scramParts(ScramSha256::verifier($password, $salt));
    $salted = hash_pbkdf2('sha256', $password, $salt, 4096, 32, true);

    expect($parts['serverKey'])->toBe(hash_hmac('sha256', 'Server Key', $salted, true));
})->with([
    'ASCII'                 => [fn (): string => testPassword()],
    'NFKC-stable non-ASCII' => [fn (): string => 'Pässwörd-Ünïcode-' . random_int(1000, 9999)],
    'not valid UTF-8'       => [fn (): string => "Latin1-\xE9t\xE9-" . random_int(1000, 9999)],
]);

// SASLprep would map or normalise these, and whether the server then keeps
// the normalised form depends on tables of unassigned code points the
// verifier does not carry. Refusing beats storing a verifier no login matches.
it('refuses a password SASLprep would change', function (string $password): void {
    expect(fn (): string => ScramSha256::verifier($password))->toThrow(WeakPassword::class, 'SASLprep');
})->with([
    'no-break space'    => ["Pass\u{00A0}word-2026!X"],
    'soft hyphen'       => ["Pass\u{00AD}word-2026!X"],
    'fullwidth letters' => ["\u{FF30}assword-2026!X"],
    'ligature'          => ["\u{FB01}ne-Password-2026!"],
]);
