<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Engines\MySqlEngine;
use Simtabi\Laranail\DBConsole\Doctor\MinimalAdminGrant;

/*
 * The live-tls job provisions its MySQL admins with its own copy of the
 * minimal grant. MinimalAdminGrantTest pins the first copy in the workflow to
 * DoctorService's recommendation; this pins every copy to it, so neither the
 * live-tls nor the live-mysql job can drift onto a broader admin unnoticed.
 * It reads the workflow file, so it runs everywhere, not only in that job.
 */

it('provisions every MySQL admin in CI with the recommended grant', function (): void {
    $workflow = (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/tests.yml');

    expect(preg_match_all('/# >>> minimal-admin\n(.*?)\n\s*# <<< minimal-admin/s', $workflow, $blocks))->toBe(2);

    foreach ($blocks[1] as $block) {
        $lines = array_map(
            static fn (string $line): string => str_replace("IDENTIFIED BY 'secret'", "IDENTIFIED BY '...'", preg_replace('/^\s*echo "(.*)"$/', '$1', $line) ?? ''),
            array_values(array_filter(explode("\n", $block), static fn (string $line): bool => str_contains($line, 'echo "'))),
        );

        expect($lines)->toBe(MinimalAdminGrant::statements(new MySqlEngine));
    }
});

it('runs the live-tls servers with TLS enforced, not merely available', function (): void {
    $workflow = (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/tests.yml');

    expect($workflow)->toContain('--require-secure-transport=ON')
        ->and($workflow)->toContain('-c ssl=on')
        ->and($workflow)->toContain("'hostnossl all all all reject' 'hostssl all all all scram-sha-256'")
        ->and($workflow)->toContain('CREATEDB CREATEROLE NOSUPERUSER');
});
