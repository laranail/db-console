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
 * Grant a preset (or custom privilege list) to an account on a database.
 */
final class GrantCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.grant {--server=} {--user=} {--host=} {--db=} {--preset=} {--privileges=*}';

    protected $description = 'Grant a preset or custom privileges to an account on a database';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:grant'];

    public function handle(PrivilegeManager $privileges): int
    {
        $user = $this->str('user');
        $host = $this->str('host') ?: '%';
        $db = $this->str('db');
        $presetValue = $this->str('preset') ?: 'read_only';

        if ($user === '' || $db === '') {
            $this->failure('--user and --db are required.');

            return self::FAILURE;
        }

        try {
            $preset = PrivilegePreset::from($presetValue);
            /** @var list<string> $custom */
            $custom = (array) $this->option('privileges');
            $set = $preset === PrivilegePreset::Custom ? PrivilegeSet::custom($custom) : PrivilegeSet::fromPreset($preset);

            $privileges->grant($this->server(), new Username($user), new Host($host), new DbName($db), $set);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        } catch (ValueError) {
            $this->failure("Unknown preset '{$presetValue}'.");

            return self::FAILURE;
        }

        $this->success("Granted {$presetValue} to '{$user}'@'{$host}' on '{$db}'.");

        return self::SUCCESS;
    }

    private function str(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}
