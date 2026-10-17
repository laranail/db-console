<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Services\Results\GrantEntry;

/*
 * One grant as every surface reports it: the CLI prints line(), the API
 * returns toArray(), and showGrants() keeps returning the lines it did.
 */

it('reads a structured row and prints it as before for a direct grant', function (): void {
    $entry = GrantEntry::fromRow([
        'object_type'    => 'table', 'database_name' => 'shop', 'object_name' => 'public.orders',
        'privilege_type' => 'SELECT', 'via' => null,
    ]);

    expect($entry->line())->toBe('table shop public.orders SELECT')
        ->and($entry->direct())->toBeTrue()
        ->and($entry->toArray())->toBe([
            'kind'      => 'table', 'database' => 'shop', 'object' => 'public.orders',
            'privilege' => 'SELECT', 'via' => null, 'readable' => true, 'line' => 'table shop public.orders SELECT',
        ]);
});

it('says where an effective privilege comes from', function (string $via): void {
    $entry = GrantEntry::fromRow([
        'object_type'    => 'schema', 'database_name' => 'shop', 'object_name' => 'public',
        'privilege_type' => 'USAGE', 'via' => $via,
    ]);

    expect($entry->line())->toBe("schema shop public USAGE via {$via}")
        ->and($entry->direct())->toBe($via === 'owner');
})->with(['PUBLIC', 'owner', 'app_writers']);

// MySQL's SHOW GRANTS returns one column holding the whole statement.
it('keeps a row it cannot structure as its text', function (): void {
    $entry = GrantEntry::fromRow(['Grants for shop@%' => 'GRANT SELECT ON `shop`.* TO `shop`@`%`']);

    expect($entry->line())->toBe('GRANT SELECT ON `shop`.* TO `shop`@`%`')
        ->and($entry->kind)->toBeNull()
        ->and($entry->direct())->toBeTrue();
});

it('marks a database whose grants the admin could not read', function (): void {
    $entry = GrantEntry::unreadable('locked');

    expect($entry->readable)->toBeFalse()
        ->and($entry->line())->toBe('database locked not readable: the admin may not connect to it')
        ->and($entry->toArray()['database'])->toBe('locked');
});
