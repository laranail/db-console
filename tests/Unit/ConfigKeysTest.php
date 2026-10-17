<?php

declare(strict_types=1);

/*
 * A PHP array literal with the same key twice keeps the last one and says
 * nothing. Landing several branches together once left config/db-console.php
 * with two top-level 'security' blocks: the second, holding only step_up,
 * replaced the first, and every operator-assurance setting fell back to its
 * code default. This reads the file's tokens, so it sees what the parsed
 * array cannot: the key that was thrown away.
 */

/**
 * Every literal key written twice in the same array, as "path.key".
 *
 * @return list<string>
 */
function duplicateConfigKeys(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $scopes = [];          // one list of seen keys per open array
    $path = [];            // the key each open array was assigned to
    $pendingKey = null;    // the key whose value comes next
    $duplicates = [];

    foreach ($tokens as $i => $token) {
        if ($token === '[') {
            $scopes[] = [];
            $path[] = $pendingKey ?? '?';
            $pendingKey = null;

            continue;
        }

        if ($token === ']') {
            array_pop($scopes);
            array_pop($path);

            continue;
        }

        if ($scopes === [] || ! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $next = $tokens[$i + 1] ?? null;
        if (! is_array($next) || $next[0] !== T_DOUBLE_ARROW) {
            continue;
        }

        $key = trim($token[1], '\'"');
        $depth = count($scopes) - 1;
        if (in_array($key, $scopes[$depth], true)) {
            $duplicates[] = implode('.', array_slice([...$path, $key], 1));
        }
        $scopes[$depth][] = $key;
        $pendingKey = $key;
    }

    return $duplicates;
}

it('writes no key twice in the same array of the shipped config', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/config/db-console.php');

    // Non-vacuity: the scan has to see the keys it is guarding.
    expect(substr_count($source, "'security' =>"))->toBeGreaterThanOrEqual(1)
        ->and(duplicateConfigKeys($source))->toBe([]);
});

it('catches a top-level key written twice', function (): void {
    $source = "<?php return ['security' => ['assurance' => []], 'audit' => [], 'security' => ['step_up' => []]];";

    expect(duplicateConfigKeys($source))->toBe(['security']);
});

it('catches a nested key written twice, and not the same key in sibling arrays', function (): void {
    $source = "<?php return ['servers' => ['primary' => ['hosting' => 'a', 'hosting' => 'b']], 'a' => ['ttl' => 1], 'b' => ['ttl' => 2]];";

    expect(duplicateConfigKeys($source))->toBe(['servers.primary.hosting']);
});
