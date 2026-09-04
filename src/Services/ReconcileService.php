<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Events\ReconcileDriftFound;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Services\Results\ReconcileReport;

/**
 * Reconciles the catalog against the live server (section 5). Reads are live
 * — the server is authoritative — so it reports drift (orphans, unmanaged
 * objects) rather than trusting the catalog, and NEVER auto-mutates the
 * server: silently "fixing" a production database is how you cause an outage.
 * The operator chooses to adopt unmanaged objects (--adopt) or leave the
 * report as-is. Reconciliation is audited.
 */
final readonly class ReconcileService
{
    public function __construct(
        private ServerRegistry $registry,
        private Authorizer $authorizer,
        private Dispatcher $events,
        private DatabaseManager $databases,
        private AccountManager $accounts,
    ) {}

    /**
     * Diff the catalog against the live server. With $adopt, pull unmanaged
     * live objects into the catalog (marked not-managed-by-DBConsole); still
     * never touches the server.
     */
    public function reconcile(string $server, bool $adopt = false): ReconcileReport
    {
        $this->authorizer->authorize(ConsolePermission::ServerView, "server:{$server}");
        $this->registry->ensureReachable($server);

        $liveDatabases = $this->databases->list($server);
        $liveAccounts = $this->normalizeAccounts($this->accounts->list($server));

        $catalogDatabases = ManagedDatabase::query()->where('server_name', $server)->pluck('name')->all();
        $catalogAccounts = DbAccount::query()->where('server_name', $server)->get()
            ->map(static fn (DbAccount $a): string => $a->username)
            ->all();

        $orphanDatabases = array_values(array_diff($this->strings($catalogDatabases), $liveDatabases));
        $unmanagedDatabases = array_values(array_diff($liveDatabases, $this->strings($catalogDatabases)));
        $orphanAccounts = array_values(array_diff($this->strings($catalogAccounts), $liveAccounts));
        $unmanagedAccounts = array_values(array_diff($liveAccounts, $this->strings($catalogAccounts)));

        $adopted = 0;
        if ($adopt) {
            $adopted = $this->adopt($server, $unmanagedDatabases);
        }

        $report = new ReconcileReport(
            server: $server,
            orphanDatabases: $orphanDatabases,
            unmanagedDatabases: $unmanagedDatabases,
            orphanAccounts: $orphanAccounts,
            unmanagedAccounts: $unmanagedAccounts,
            adopted: $adopted,
        );

        if ($report->hasDrift()) {
            $this->events->dispatch(new ReconcileDriftFound($server, [
                'target' => $server,
                'drift'  => $report->driftCount(),
            ]));
        }

        return $report;
    }

    /**
     * Adopt unmanaged databases into the catalog as not-managed-by-DBConsole
     * rows (a record of "seen", not "created here"). Never touches the server.
     *
     * @param list<string> $databases
     */
    private function adopt(string $server, array $databases): int
    {
        $count = 0;
        foreach ($databases as $name) {
            $adopted = ManagedDatabase::query()->firstOrCreate(
                ['server_name' => $server, 'name' => (new DbName($name))->value],
                ['is_managed' => false],
            );

            if ($adopted->wasRecentlyCreated) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Account listings come back as "user@host"; reconcile on the username.
     *
     * @param list<string> $accounts
     *
     * @return list<string>
     */
    private function normalizeAccounts(array $accounts): array
    {
        return array_values(array_unique(array_map(
            static fn (string $a): string => explode('@', $a)[0],
            $accounts,
        )));
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_map(strval(...), $values));
    }
}
