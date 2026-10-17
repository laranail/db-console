<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Privilege;
use Simtabi\Laranail\DBConsole\Engines\MySqlEngine;
use Simtabi\Laranail\DBConsole\Engines\MariaDbEngine;
use Simtabi\Laranail\DBConsole\Engines\PostgresEngine;
use Simtabi\Laranail\DBConsole\Doctor\MinimalAdminGrant;

/*
 * The grant doctor recommends is the grant the live-mysql CI job runs as, so
 * it is the one proven against a real server. The job, the README and the
 * recipe each spell it out; these tests keep all four the same.
 */

function minimalGrantRepo(string $path): string
{
    $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    expect($contents)->toBeString();

    return (string) $contents;
}

it('lets the admin pass on every privilege a preset can grant', function (): void {
    $grantOption = collect(MinimalAdminGrant::statements(new MySqlEngine))
        ->first(fn (string $line): bool => str_ends_with($line, 'WITH GRANT OPTION;'));

    expect($grantOption)->toBeString()
        ->and(preg_match('/^GRANT (.+) ON \*\.\* TO /', (string) $grantOption, $match))->toBe(1);

    $granted = explode(', ', $match[1]);

    // GRANT needs the privilege and GRANT OPTION on it, so a privilege missing
    // here fails some preset at its grant step, only on a real server.
    foreach (Privilege::cases() as $privilege) {
        expect($granted)->toContain(strtoupper(str_replace('_', ' ', $privilege->value)));
    }
});

it('asks for nothing db-console does not use', function (): void {
    $grants = implode("\n", MinimalAdminGrant::statements(new MariaDbEngine));

    expect($grants)->not->toContain('RELOAD')
        ->and($grants)->not->toContain('SUPER')
        ->and($grants)->not->toContain('ALL PRIVILEGES');
});

it('recommends a CREATEDB CREATEROLE role on Postgres', function (): void {
    expect(MinimalAdminGrant::statements(new PostgresEngine))
        ->toBe(["CREATE ROLE db_console_admin WITH LOGIN CREATEDB CREATEROLE PASSWORD '...';"]);
});

it('is the grant the live-mysql job provisions its admin with', function (): void {
    $workflow = minimalGrantRepo('.github/workflows/tests.yml');

    expect(preg_match('/# >>> minimal-admin\n(.*?)\n\s*# <<< minimal-admin/s', $workflow, $block))->toBe(1);

    $lines = array_map(
        static fn (string $line): string => str_replace("IDENTIFIED BY 'secret'", "IDENTIFIED BY '...'", preg_replace('/^\s*echo "(.*)"$/', '$1', $line) ?? ''),
        array_values(array_filter(explode("\n", $block[1]), static fn (string $line): bool => str_contains($line, 'echo "'))),
    );

    expect($lines)->toBe(MinimalAdminGrant::statements(new MySqlEngine));
});

it('is the grant the README and the recipe tell people to run', function (string $path): void {
    $doc = minimalGrantRepo($path);

    foreach (MinimalAdminGrant::statements(new MySqlEngine) as $statement) {
        expect($doc)->toContain(str_replace("'...'", "'…'", $statement));
    }

    expect($doc)->not->toContain('RELOAD');
})->with(['README.md', 'docs/recipes/provision-minimal-admin.md']);
