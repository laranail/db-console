<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Encryption\SqlCipherManager;
use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;

/**
 * Report TLS status per server and the catalog encryption mode.
 */
final class EncryptionStatusCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.encryption:status {--server=}';

    protected $description = 'Report TLS status per server and the catalog encryption mode';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:encryption:status'];

    public function handle(TlsChecker $tls, SqlCipherManager $sqlcipher): int
    {
        $server = $this->server();

        $this->line("Server '{$server}':");
        $this->line('  TLS: ' . $tls->status($server)->value . ($tls->isLocal($server) ? ' (local)' : ''));
        foreach ($tls->problems($server) as $problem) {
            $this->line("  [{$problem['severity']->value}] {$problem['message']}");
        }

        $report = $sqlcipher->report();
        $this->line('Catalog encryption: ' . $report['mode'] . ($report['reason'] !== null ? " — {$report['reason']}" : ''));

        return self::SUCCESS;
    }
}
