<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use ValueError;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * Attach one user to/from many databases in a single batch (repeatable --db).
 */
final class AttachCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.attach {--server=} {--user=} {--host=} {--db=*} {--preset=}';

    protected $description = 'Attach a user to/from databases in one batch';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:attach'];

    public function handle(PrivilegeManager $privileges): int
    {
        $user = $this->str('user');
        $host = $this->str('host') ?: '%';
        /** @var list<string> $dbs */
        $dbs = (array) $this->option('db');
        $presetValue = $this->str('preset') ?: 'read_write';

        if ($user === '' || $dbs === []) {
            $this->failure('--user and at least one --db are required.');

            return self::FAILURE;
        }

        try {
            $set = PrivilegeSet::fromPreset(PrivilegePreset::from($presetValue));
            $databases = array_map(static fn (string $d): DbName => new DbName($d), $dbs);
            $result = $privileges->attach($this->server(), new Username($user), new Host($host), $databases, $set);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        } catch (ValueError) {
            $this->failure("Unknown preset '{$presetValue}'.");

            return self::FAILURE;
        }

        foreach ($result->succeeded() as $p) {
            $this->components->info("ok: {$p['user']}@{$p['host']} · {$p['database']}");
        }
        foreach ($result->failed() as $p) {
            $this->components->error("failed: {$p['user']}@{$p['host']} · {$p['database']} — {$p['error']}");
        }

        $this->line(count($result->succeeded()) . ' succeeded, ' . count($result->failed()) . ' failed.');

        return $result->allSucceeded() ? self::SUCCESS : self::FAILURE;
    }

    private function str(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}
