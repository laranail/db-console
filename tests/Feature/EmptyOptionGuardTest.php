<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Console\Commands\AuditViewCommand;
use Simtabi\Laranail\DBConsole\Console\Commands\DBConsoleCommand;
use Simtabi\Laranail\Package\Tools\Testing\AssertsDriverContract;

uses(AssertsDriverContract::class);

/**
 * `--limit=twenty` cast to `0`, and `limit(0)` returns no rows -- so an audit
 * view with a mistyped limit printed nothing, which reads exactly like "there
 * is no audit history" rather than like a bad argument.
 */
it('falls back to the documented limit when --limit is not numeric', function (): void {
    $source = (string) file_get_contents((string) (new ReflectionClass(AuditViewCommand::class))->getFileName());

    expect($source)->not->toContain("(int) \$this->option('limit')")
        ->and($source)->toContain("intOption('limit', 25)");
});

it('the base command exposes the normalising accessors', function (): void {
    foreach (['strOption', 'intOption', 'arrayOption', 'boolOption'] as $method) {
        expect(method_exists(DBConsoleCommand::class, $method))->toBeTrue("base must expose {$method}()");
    }
});

it('has no console option defaulted by a null-only test', function (): void {
    $this->assertNoNullOnlyOptionGuards(__DIR__ . '/../../src');
});
