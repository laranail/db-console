<?php

declare(strict_types=1);

use Illuminate\View\Component;

/*
 * Architecture invariants (section 4a, 11, 13, 27). Two of these — "only
 * Engines build SQL" and "secrets are never logged" — must never be
 * weakened.
 */

arch('every source file declares strict types')
    ->expect('Simtabi\Laranail\DBConsole')
    ->toUseStrictTypes();

arch('domain value objects never depend on the engines or services')
    ->expect('Simtabi\Laranail\DBConsole\Domain')
    ->not->toUse([
        'Simtabi\Laranail\DBConsole\Engines',
        'Simtabi\Laranail\DBConsole\Services',
    ]);

arch('enums do not depend on engines, services, or models')
    ->expect('Simtabi\Laranail\DBConsole\Enums')
    ->not->toUse([
        'Simtabi\Laranail\DBConsole\Engines',
        'Simtabi\Laranail\DBConsole\Services',
        'Simtabi\Laranail\DBConsole\Models',
    ]);

arch('the core is headless — it never references Livewire, Flux, or Blade UI')
    ->expect('Simtabi\Laranail\DBConsole')
    ->not->toUse([
        'Livewire\\Livewire',
        'Livewire\\Component',
        'Flux\\Flux',
        Component::class,
    ]);

test('the core ships no Blade views, Livewire components, or UI assets (headless)', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_dir("{$root}/resources/views"))->toBeFalse('core must not ship Blade views')
        ->and(glob("{$root}/src/**/*Livewire*") ?: [])->toBe([])
        ->and(array_filter(
            (array) glob("{$root}/composer.json"),
            static fn (string $path): bool => str_contains((string) file_get_contents($path), 'livewire/livewire')
                || str_contains((string) file_get_contents($path), 'livewire/flux'),
        ))->toBe([], 'core composer.json must not depend on Livewire/Flux');
});

/**
 * The load-bearing invariant: the Statement value object is the sole
 * carrier of an executable statement string, and ONLY engine classes may
 * call its factory (Statement::plain / Statement::sensitive). Everything
 * else — services, commands, controllers, models — must obtain statements
 * from an engine, never mint them. Statement.php itself (the definition)
 * and the Engines/ directory (the sanctioned producers) are exempt.
 *
 * @return list<string> src files, outside Engines/, that mint a Statement
 */
function nonEngineFilesMintingStatements(): array
{
    $srcDir = dirname(__DIR__, 2).'/src';
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
    );

    $factoryCall = '/\bStatement::(plain|sensitive)\s*\(/';

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = (string) $file->getRealPath();
        $relative = str_replace($srcDir.DIRECTORY_SEPARATOR, '', $path);
        // The sanctioned producers, and the Statement definition itself.
        if (str_starts_with($relative, 'Engines'.DIRECTORY_SEPARATOR)) {
            continue;
        }
        if ($relative === 'Domain'.DIRECTORY_SEPARATOR.'Statement.php') {
            continue;
        }

        $contents = (string) file_get_contents($path);
        if (preg_match($factoryCall, $contents) === 1) {
            $offenders[] = $relative;
        }
    }

    return $offenders;
}

test('only Engines mint statements — nothing outside Engines/ calls the Statement factory (must never be weakened)', function (): void {
    expect(nonEngineFilesMintingStatements())->toBe([]);
});

test('the Statement factory scan actually detects a violation (guards against a tautology)', function (): void {
    // A control: a temp file in a non-engine dir that mints a Statement is
    // caught. This proves the scan above is not vacuously green.
    $srcDir = dirname(__DIR__, 2).'/src';
    $probeDir = $srcDir.'/Services';
    $probe = $probeDir.'/__ArchProbe.php';

    if (! is_dir($probeDir)) {
        mkdir($probeDir, 0o755, true);
    }
    file_put_contents($probe, "<?php\nStatement::plain('SELECT 1');\n");

    try {
        expect(nonEngineFilesMintingStatements())->toContain('Services/__ArchProbe.php');
    } finally {
        @unlink($probe);
    }
});
