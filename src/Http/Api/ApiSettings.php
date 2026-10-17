<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * The REST API's settings, read from the keys config/db-console.php documents.
 *
 * Until 0.1 the code read `api.guard` and `api.prefix` while the config file
 * defined `api.auth` and `api.path`, so the documented keys did nothing. The
 * documented keys are read now; the old ones are still honoured when the
 * documented key is absent, with a deprecation notice, and are removed no
 * earlier than the next minor after 0.1.
 */
final readonly class ApiSettings
{
    public const string RATE_LIMITER = 'laranail-db-console.api';

    public function __construct(private Config $config) {}

    /**
     * The auth guard the API authenticates with (sanctum | passport | any guard).
     */
    public function guard(): string
    {
        return $this->documentedOrDeprecated('auth', 'guard', 'sanctum');
    }

    /**
     * The URI prefix the API is mounted under.
     */
    public function path(): string
    {
        return trim($this->documentedOrDeprecated('path', 'prefix', 'api/db-console'), '/');
    }

    public function requiresHttps(): bool
    {
        return (bool) $this->config->get('laranail.db-console.api.require_https', true);
    }

    public function confirmsDestructive(): bool
    {
        return (bool) $this->config->get('laranail.db-console.api.confirm_destructive', true);
    }

    /**
     * `api.rate_limit` as [requests, minutes], or null when it is switched
     * off (null, '' or 0). Accepts 'requests,minutes' or a bare request
     * count per minute.
     *
     * @return array{0: int, 1: int}|null
     */
    public function rateLimit(): ?array
    {
        $raw = $this->config->get('laranail.db-console.api.rate_limit', '60,1');
        if (in_array($raw, [null, '', false], true)) {
            return null;
        }

        $parts = array_map(trim(...), explode(',', is_scalar($raw) ? (string) $raw : ''));
        $requests = (int) ($parts[0] ?? 0);
        $minutes = max(1, (int) ($parts[1] ?? 1));

        return $requests > 0 ? [$requests, $minutes] : null;
    }

    private function documentedOrDeprecated(string $key, string $deprecated, string $default): string
    {
        $value = $this->config->get("laranail.db-console.api.{$key}");
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $old = $this->config->get("laranail.db-console.api.{$deprecated}");
        if (is_string($old) && $old !== '') {
            DeprecatedConfigKeys::announce("api.{$deprecated}", "api.{$key}");

            return $old;
        }

        return $default;
    }
}
