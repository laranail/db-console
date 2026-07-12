<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Audit\AuditChain;

/**
 * Verify the audit hash-chain for tamper-evidence.
 */
final class AuditVerifyCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.audit:verify';

    protected $description = 'Verify the audit trail hash-chain (tamper-evidence)';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:audit:verify'];

    public function handle(AuditChain $chain): int
    {
        $result = $chain->verify();

        if ($result['valid']) {
            $this->components->info("Audit chain intact: {$result['checked']} row(s) verified.");

            return self::SUCCESS;
        }

        $this->failure("Audit chain BROKEN at row {$result['broken_at']} (checked {$result['checked']}). Tampering detected.");

        return self::FAILURE;
    }
}
