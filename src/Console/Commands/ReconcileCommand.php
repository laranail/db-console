<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Services\ReconcileService;

/**
 * Diff the catalog against the live server and report drift (--adopt to pull
 * unmanaged objects into the catalog). Never mutates the server.
 */
final class ReconcileCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.reconcile {--server=} {--adopt}';

    protected $description = 'Reconcile the catalog against the live server (report-only; --adopt to record unmanaged)';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:reconcile'];

    public function handle(ReconcileService $reconcile): int
    {
        try {
            $report = $reconcile->reconcile($this->server(), (bool) $this->option('adopt'));
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->section('Orphan databases (in catalog, not on server)', $report->orphanDatabases);
        $this->section('Unmanaged databases (on server, not in catalog)', $report->unmanagedDatabases);
        $this->section('Orphan accounts', $report->orphanAccounts);
        $this->section('Unmanaged accounts', $report->unmanagedAccounts);

        if ($report->adopted > 0) {
            $this->components->info("Adopted {$report->adopted} unmanaged object(s) into the catalog.");
        }

        $this->components->info($report->hasDrift() ? "Drift: {$report->driftCount()} item(s)." : 'No drift.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $items
     */
    private function section(string $title, array $items): void
    {
        if ($items === []) {
            return;
        }
        $this->line("<comment>{$title}</comment>");
        foreach ($items as $item) {
            $this->line("  {$item}");
        }
    }
}
