<?php

declare(strict_types=1);

/*
 * Test passwords are generated at run time through testPassword(), never
 * written into the source. A fixed strong sample in these tests was reported
 * by secret scanning as a leaked credential; every literal is one more alert
 * to triage, and one a real password could hide among.
 *
 * "Looks strong" is the scanner's own heuristic: twelve or more characters
 * mixing upper case, lower case, a digit and a symbol.
 *
 * Secret scanning's generic detectors also flag a weak literal once its name
 * says it is a credential, so a constant, a named argument or a Livewire
 * property named for a password, secret or token may hold no literal at all.
 * That check reads names only: an RFC vector such as RFC7677_SALT, or the
 * password a hashing test feeds positionally, is not named for a credential
 * and stays exact.
 */
function looksLikeAStrongPassword(string $literal): bool
{
    return strlen($literal) >= 12
        && preg_match('/[A-Z]/', $literal) === 1
        && preg_match('/[a-z]/', $literal) === 1
        && preg_match('/\d/', $literal) === 1
        && preg_match('/[^A-Za-z0-9\s]/', $literal) === 1
        && preg_match('/\s/', $literal) === 0
        // Namespaces, paths, URLs and identifiers are not passwords.
        && preg_match('#^[\\\\/]|\\\\|://|^[\w.-]+$#', $literal) === 0;
}

/**
 * Literals in a password position: a Password value object, a `password` key,
 * a variable or constant named for one. Other strings (RFC test vectors,
 * socket paths, regexes) are not credentials and are left alone.
 *
 * @return list<string>
 */
function strongLookingLiteralsIn(string $source): array
{
    $positions = [
        '/new\s+Password\(\s*([\'"])(.*?)\1/s',
        '/[\'"]\w*password\w*[\'"]\s*=>\s*([\'"])(.*?)\1/is',
        '/\$\w*(?:password|passwd|\bpw)\w*\s*=\s*([\'"])(.*?)\1/is',
        '/\bconst\s+\w*(?:PASSWORD|PW)\w*\s*=\s*([\'"])(.*?)\1/s',
    ];

    $found = [];
    foreach ($positions as $pattern) {
        preg_match_all($pattern, $source, $matches);
        foreach ($matches[2] as $literal) {
            // Decode PHP's \u{...} escapes as PHP would, so an escape's hex
            // digits are not mistaken for the password's.
            $decoded = (string) preg_replace_callback('/\\\\u\{([0-9A-Fa-f]+)\}/', static fn (array $m): string => mb_chr((int) hexdec($m[1])), $literal);

            if (looksLikeAStrongPassword(stripcslashes($decoded))) {
                $found[] = $literal;
            }
        }
    }

    return $found;
}

/**
 * Literals of any strength in a position named for a credential: a constant
 * or define(), a named argument, or a Livewire set()/assertSet() property.
 *
 * @return list<string>
 */
function namedCredentialLiteralsIn(string $source): array
{
    $credential = '\w*(?:password|passwd|secret|token)\w*';
    $positions = [
        '/\bconst\s+(?:string\s+)?' . $credential . '\s*=\s*([\'"])(.+?)\1/is',
        '/\bdefine\(\s*[\'"]' . $credential . '[\'"]\s*,\s*([\'"])(.+?)\1/is',
        '/[(,]\s*' . $credential . '\s*:\s*([\'"])(.+?)\1/is',
        '/->(?:set|assertSet)\(\s*[\'"]' . $credential . '[\'"]\s*,\s*([\'"])(.+?)\1/is',
    ];

    $found = [];
    foreach ($positions as $pattern) {
        preg_match_all($pattern, $source, $matches);
        foreach ($matches[2] as $literal) {
            $found[] = $literal;
        }
    }

    return $found;
}

it('recognises the sample it replaced, so the scan can fail', function (): void {
    expect(strongLookingLiteralsIn("<?php new Password('Ab9\$cD3#eF7!gH');"))->toHaveCount(1)
        ->and(strongLookingLiteralsIn("<?php \$adminPassword = 'Ab9\$cD3#eF7!gH';"))->toHaveCount(1)
        ->and(strongLookingLiteralsIn("<?php ['password' => 'Ab9\$cD3#eF7!gH'];"))->toHaveCount(1)
        ->and(strongLookingLiteralsIn("<?php const LIVE_PW = 'Ab9\$cD3#eF7!gH';"))->toHaveCount(1)
        ->and(strongLookingLiteralsIn("<?php new Password('Ab1!short');"))->toBe([])
        ->and(strongLookingLiteralsIn("<?php \$vector = 'Ab9\$cD3#eF7!gH';"))->toBe([]);
});

/**
 * A sample source with the name and the value filled in apart, so the sample
 * is not itself a name-and-literal pair for secret scanning to report.
 */
function credentialSample(string $template, string $name, string $value): string
{
    return '<?php ' . strtr($template, ['NAME' => $name, 'VALUE' => $value]);
}

it('recognises a weak literal in a position named for a credential, and only there', function (): void {
    expect(namedCredentialLiteralsIn(credentialSample("const NAME = 'VALUE';", 'POLICY_THEIR_PASSWORD', 'theirs')))->toHaveCount(1)
        ->and(namedCredentialLiteralsIn(credentialSample("const string NAME = 'VALUE';", 'ADMIN_SECRET', 'not-real')))->toHaveCount(1)
        ->and(namedCredentialLiteralsIn(credentialSample("define('NAME', 'VALUE');", 'LIVE_TOKEN', 'abc')))->toHaveCount(1)
        ->and(namedCredentialLiteralsIn(credentialSample("result(applied: true, NAME: 'VALUE');", 'password', 'shown-once')))->toHaveCount(1)
        ->and(namedCredentialLiteralsIn(credentialSample("\$c->assertSet('NAME', 'VALUE');", 'generatedPassword', 'shown-once')))->toHaveCount(1)
        ->and(namedCredentialLiteralsIn(credentialSample("const NAME = 'VALUE';", 'RFC7677_SALT', base64_encode(random_bytes(16)))))->toBe([])
        ->and(namedCredentialLiteralsIn("<?php ScramSha256::verifier('pencil', \$salt);"))->toBe([])
        ->and(namedCredentialLiteralsIn("<?php define('LIVE_PASSWORD', testPassword(24));"))->toBe([])
        ->and(namedCredentialLiteralsIn("<?php \$c->assertSet('flash', 'Its password is unchanged.');"))->toBe([]);
});

it('keeps credential-looking literals out of the test suite', function (): void {
    $files = 0;
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
            continue;
        }

        $files++;
        $source = (string) file_get_contents($file->getPathname());
        foreach ([...strongLookingLiteralsIn($source), ...namedCredentialLiteralsIn($source)] as $literal) {
            $offenders[] = basename($file->getPathname()) . ': ' . substr($literal, 0, 3) . '…';
        }
    }

    expect($files)->toBeGreaterThan(50)
        ->and($offenders)->toBe([]);
});
