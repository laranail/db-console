<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;

/**
 * Probe every registered server (reachability, TLS, capabilities, admin
 * privilege sanity) and report the secret driver + catalog encryption mode.
 * Exits non-zero if any finding is an error — so setup won't proceed insecure.
 */
final class DoctorCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.doctor';

    protected $description = 'Health-check every registered server and report the security posture';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:doctor'];

    public function handle(DoctorService $doctor): int
    {
        $findings = $doctor->run();
        $doctor->alertOnSecurityFindings($findings);

        $hasError = false;
        foreach ($findings as $finding) {
            match ($finding->severity) {
                Severity::Error   => $this->components->error("{$finding->check}: {$finding->message}"),
                Severity::Warning => $this->components->warn("{$finding->check}: {$finding->message}"),
                default           => $this->components->info("{$finding->check}: {$finding->message}"),
            };

            if ($finding->remediation !== null) {
                $this->line('');
                $this->line('  <comment>Fix:</comment>');
                foreach (explode("\n", $finding->remediation) as $line) {
                    $this->line("    {$line}");
                }
                $this->line('');
            }

            $hasError = $hasError || $finding->isError();
        }

        if ($hasError) {
            $this->failure('doctor found blocking problems. Fix them before continuing.');

            return self::FAILURE;
        }

        $this->success('doctor: all checks passed.');

        return self::SUCCESS;
    }
}
