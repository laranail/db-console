<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Support\DriverBoolean;

// Postgres returns booleans, MySQL and SQLite 1/0, and some drivers 't'/'f'.
// One reading of them, where four services each had their own copy.
it('reads a database driver\'s boolean the same way everywhere', function (mixed $value, bool $expected): void {
    expect(DriverBoolean::truthy($value))->toBe($expected);
})->with([
    [true, true], [1, true], ['1', true], ['t', true], ['true', true],
    [false, false], [0, false], ['0', false], ['f', false], ['false', false], [null, false], ['yes', false],
]);
