<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Services\Results\GrantEntry;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Show what an account may do on a server, read live. On Postgres each line
 * says where a privilege comes from when it is not granted to the account
 * itself (via owner, PUBLIC or an inherited role); --direct keeps the
 * account's own.
 */
final class UserGrantsCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.user:grants {--server=} {--user=} {--host=%} {--direct : Only privileges held by the account itself} {--json : Print the entries as JSON}';

    protected $description = 'Show the privileges an account holds, read live from the server';

    public function handle(PrivilegeManager $privileges): int
    {
        $user = $this->opt('user');
        if ($user === '') {
            $this->failure('Name the account with --user.');

            return self::FAILURE;
        }

        $server = $this->server();
        $host = $this->opt('host') !== '' ? $this->opt('host') : '%';

        try {
            $entries = $privileges->grants($server, new Username($user), new Host($host), (bool) $this->option('direct'));
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (GrantEntry $entry): array => $entry->toArray(), $entries),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        foreach ($entries as $entry) {
            $this->line('  ' . $entry->line());
        }
        $this->components->info(count($entries) . " privilege(s) for '{$user}' on '{$server}'.");

        return self::SUCCESS;
    }
}
