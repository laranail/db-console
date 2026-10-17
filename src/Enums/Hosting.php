<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\DBConsole\Domain\HostingProfile;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * Where a server runs. A managed provider owns accounts DBConsole must not
 * touch, scopes every app account to any host, and keeps point-in-time
 * recovery that beats a dump; a self-hosted server does none of that.
 *
 * Declared per server (`servers.<name>.hosting`) and never inferred: an
 * absent key is SelfHosted, so an existing deployment behaves exactly as it
 * did. Doctor warns when a self-hosted server's host names a provider.
 *
 * The provider account lists come from each provider's documentation and are
 * verified against a live cluster only where a test says so.
 */
enum Hosting: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Self-hosted')]
    case SelfHosted = 'self_hosted';

    #[Label('DigitalOcean Managed Databases')]
    case DigitalOcean = 'digitalocean';

    #[Label('Amazon RDS')]
    case Rds = 'rds';

    #[Label('Google Cloud SQL')]
    case CloudSql = 'cloud_sql';

    #[Label('Another managed provider')]
    case Generic = 'generic';

    /**
     * Host-name suffixes that identify a provider. Cloud SQL is reached by IP
     * or through its proxy, so it has none.
     */
    private const array HOST_SUFFIXES = [
        '.ondigitalocean.com' => 'digitalocean',
        '.rds.amazonaws.com'  => 'rds',
    ];

    /**
     * The provider a host name belongs to, or null when it names none.
     */
    public static function detectFromHost(?string $host): ?self
    {
        if ($host === null || $host === '') {
            return null;
        }

        $host = strtolower($host);

        foreach (self::HOST_SUFFIXES as $suffix => $hosting) {
            if (str_ends_with($host, $suffix)) {
                return self::from($hosting);
            }
        }

        return null;
    }

    public function isManaged(): bool
    {
        return $this !== self::SelfHosted;
    }

    /**
     * What this hosting means for one engine.
     */
    public function profile(EngineType $engine): HostingProfile
    {
        if (! $this->isManaged()) {
            return new HostingProfile($this, defaultAccountHost: null, reservedAccounts: [], providerBackups: false);
        }

        return new HostingProfile(
            $this,
            // Only the MySQL family scopes accounts by host, and a managed
            // server is never reached from localhost.
            defaultAccountHost: $engine->isMysqlFamily() ? '%' : null,
            reservedAccounts: $this->reservedAccounts($engine),
            providerBackups: true,
        );
    }

    /**
     * Glob patterns (fnmatch) for accounts the provider owns.
     *
     * @return list<string>
     */
    private function reservedAccounts(EngineType $engine): array
    {
        $system = match (true) {
            $engine->isMysqlFamily()      => ['mysql.*', 'mariadb.sys'],
            $engine === EngineType::Pgsql => ['pg_*'],
            default                       => [],
        };

        $provider = match ($this) {
            self::DigitalOcean              => ['doadmin', '_dodb'],
            self::Rds                       => ['rdsadmin', 'rdsrepladmin', 'rds_*'],
            self::CloudSql                  => ['cloudsql*'],
            self::Generic, self::SelfHosted => [],
        };

        return [...$provider, ...$system];
    }
}
