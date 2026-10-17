<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

use Simtabi\Laranail\DBConsole\Enums\Hosting;

/**
 * What a server's hosting means for one engine. Built by Hosting::profile()
 * and merged into Capabilities, so the UI, the CLI and the services read the
 * same answer.
 */
final readonly class HostingProfile
{
    /**
     * @param ?string $defaultAccountHost host a new account gets when none is given;
     *                                    null keeps `accounts.default_host`
     * @param list<string> $reservedAccounts fnmatch patterns for accounts the provider owns
     * @param bool $providerBackups the provider's point-in-time recovery is the primary
     *                              backup, so the dump before a drop is opt-in
     */
    public function __construct(
        public Hosting $hosting,
        public ?string $defaultAccountHost,
        public array $reservedAccounts,
        public bool $providerBackups,
    ) {}

    /**
     * Whether an account name belongs to the provider (or the engine itself),
     * so DBConsole lists it as neither manageable nor droppable.
     */
    public function isReserved(string $account): bool
    {
        return array_any($this->reservedAccounts, fn (string $pattern): bool => fnmatch($pattern, $account, FNM_NOESCAPE));
    }
}
