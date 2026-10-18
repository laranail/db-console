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

it('recognises the sample it replaced, so the scan can fail', function (): void {
    expect(strongLookingLiteralsIn("<?php new Password('Ab9\$cD3#eF7!gH');"))->toHaveCount(1)
        ->and(strongLookingLiteralsIn("<?php \$adminPassword = 'Ab9\$cD3#eF7!gH';"))->toHaveCount(1)
        ->and(strongLookingLiteralsIn("<?php ['password' => 'Ab9\$cD3#eF7!gH'];"))->toHaveCount(1)
        ->and(strongLookingLiteralsIn("<?php const LIVE_PW = 'Ab9\$cD3#eF7!gH';"))->toHaveCount(1)
        ->and(strongLookingLiteralsIn("<?php new Password('Ab1!short');"))->toBe([])
        ->and(strongLookingLiteralsIn("<?php \$vector = 'Ab9\$cD3#eF7!gH';"))->toBe([]);
});

it('keeps strong-looking password literals out of the test suite', function (): void {
    $files = 0;
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
            continue;
        }

        $files++;
        foreach (strongLookingLiteralsIn((string) file_get_contents($file->getPathname())) as $literal) {
            $offenders[] = basename($file->getPathname()) . ': ' . substr($literal, 0, 3) . '…';
        }
    }

    expect($files)->toBeGreaterThan(50)
        ->and($offenders)->toBe([]);
});
