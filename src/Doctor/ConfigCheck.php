<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;

/**
 * Configuration that is valid PHP but does not do what it looks like:
 *
 *  - `api.guard` / `api.prefix`, read until 0.1.1 and ignored since. A host
 *    that still sets them would otherwise get `sanctum` and `api/db-console`
 *    without a word.
 *  - an `on_existing` that adopts by default, globally or for a server, so
 *    every existing account or database is taken over without anyone being
 *    asked. Allowed, but worth saying out loud.
 */
final readonly class ConfigCheck
{
    /** @var array<string, string> legacy key => the key that replaced it */
    private const array LEGACY_API_KEYS = ['guard' => 'auth', 'prefix' => 'path'];

    public function __construct(private Config $config) {}

    /**
     * @return list<DoctorFinding>
     */
    public function findings(): array
    {
        return [$this->legacyApiKeys(), ...$this->adoptingDefaults()];
    }

    private function legacyApiKeys(): DoctorFinding
    {
        $set = [];
        foreach (self::LEGACY_API_KEYS as $legacy => $replacement) {
            if ($this->config->get("laranail.db-console.api.{$legacy}") !== null) {
                $set[] = "api.{$legacy} (now api.{$replacement})";
            }
        }

        if ($set === []) {
            return DoctorFinding::ok('config:legacy-api-keys', 'No retired api.* keys are set.');
        }

        return DoctorFinding::error(
            'config:legacy-api-keys',
            'These keys are no longer read, so their values have no effect: ' . implode(', ', $set) . '.',
            'Rename them in config/db-console.php, or set DB_CONSOLE_API_AUTH / DB_CONSOLE_API_PATH.',
        );
    }

    /**
     * @return list<DoctorFinding>
     */
    private function adoptingDefaults(): array
    {
        $keys = [];
        foreach (ExistingResourceKind::cases() as $kind) {
            $keys[] = "laranail.db-console.{$kind->configSection()}.on_existing";

            foreach (array_keys((array) $this->config->get('laranail.db-console.servers', [])) as $server) {
                $keys[] = "laranail.db-console.servers.{$server}.{$kind->configSection()}.on_existing";
            }
        }

        $adopting = [];
        foreach ($keys as $key) {
            $value = $this->config->get($key);
            $policy = is_string($value) ? ExistingResourcePolicy::tryFrom($value) : null;
            if ($policy instanceof ExistingResourcePolicy && $policy->adopts()) {
                $adopting[] = substr($key, strlen('laranail.db-console.')) . "={$policy->value}";
            }
        }

        if ($adopting === []) {
            return [DoctorFinding::ok('config:on-existing', 'Existing accounts and databases are refused unless a call asks to adopt them.')];
        }

        return [DoctorFinding::warning(
            'config:on-existing',
            'Existing resources are adopted by default (' . implode(', ', $adopting) . '): a create takes over whatever is already there without asking.',
            'Leave on_existing at refuse and pass the policy per call (--on-existing, on_existing) when a takeover is intended.',
        )];
    }
}
