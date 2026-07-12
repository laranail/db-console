<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Models\AuditLog;

/**
 * Query the audit log.
 */
final class AuditViewCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.audit:view {--server=} {--action=} {--limit=25}';

    protected $description = 'Query the audit log';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:audit:view'];

    public function handle(): int
    {
        $query = AuditLog::query()->latest('created_at');

        $server = $this->option('server');
        if (is_string($server) && $server !== '') {
            $query->where('server', $server);
        }
        $action = $this->option('action');
        if (is_string($action) && $action !== '') {
            $query->where('action', $action);
        }

        $rows = $query->limit((int) $this->option('limit'))->get();
        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s  %-24s %-14s %s → %s',
                $row->created_at?->format('Y-m-d H:i:s') ?? '',
                $row->action->value,
                $row->server,
                $row->target ?? '',
                $row->outcome->value,
            ));
        }

        $this->components->info("{$rows->count()} audit row(s).");

        return self::SUCCESS;
    }
}
