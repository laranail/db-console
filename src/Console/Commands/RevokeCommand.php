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
 * Revoke privileges from an account on a database.
 */
final class RevokeCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.revoke {--server=} {--user=} {--host=} {--db=} {--preset=}';

    protected $description = 'Revoke privileges from an account on a database';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:revoke'];

    public function handle(PrivilegeManager $privileges): int
    {
        $user = $this->str('user');
        $host = $this->str('host') ?: '%';
        $db = $this->str('db');
        $presetValue = $this->str('preset') ?: 'full';

        if ($user === '' || $db === '') {
            $this->failure('--user and --db are required.');

            return self::FAILURE;
        }

        try {
            $preset = PrivilegePreset::from($presetValue);
            $set = PrivilegeSet::fromPreset($preset === PrivilegePreset::Custom ? PrivilegePreset::Full : $preset);
            $privileges->revoke($this->server(), new Username($user), new Host($host), new DbName($db), $set);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        } catch (ValueError) {
            $this->failure("Unknown preset '{$presetValue}'.");

            return self::FAILURE;
        }

        $this->success("Revoked from '{$user}'@'{$host}' on '{$db}'.");

        return self::SUCCESS;
    }

    private function str(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}
