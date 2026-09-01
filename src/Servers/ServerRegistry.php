<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Servers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Simtabi\Laranail\DBConsole\Engines\Engine;
use Simtabi\Laranail\DBConsole\Engines\EngineFactory;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Exceptions\ServerMisconfigured;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;
use Simtabi\Laranail\DBConsole\Exceptions\UnknownServer;
use Throwable;

/**
 * The multi-server entry point (section 5). Resolves a server name to an
 * (Engine, AdminConnection) pair, validates the config, and caches the
 * resolved pair per request. Switching servers never mixes state: each
 * operation resolves its target fresh, so an operation on server A can't
 * execute against server B.
 *
 * Server definitions come from config (static, read-only) and may later be
 * augmented by DbServer catalog rows; both surface here identically.
 */
final class ServerRegistry
{
    /** @var array<string, array{0: Engine, 1: AdminConnection}> */
    private array $resolved = [];

    /** @var array<string, ServerDefinition>|null */
    private ?array $catalogServers = null;

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
        private readonly EngineFactory $engines,
    ) {}

    /**
     * The default server name when none is given.
     */
    public function default(): string
    {
        return (string) $this->config->get('laranail.db-console.default', 'primary');
    }

    /**
     * All registered server names (config plus any catalog-backed rows).
     *
     * @return list<string>
     */
    public function names(): array
    {
        /** @var array<string, mixed> $servers */
        $servers = (array) $this->config->get('laranail.db-console.servers', []);

        $names = array_keys($servers);
        foreach (array_keys($this->catalogServers()) as $catalogName) {
            if (! in_array($catalogName, $names, true)) {
                $names[] = $catalogName;
            }
        }

        /** @var list<string> */
        return array_values(array_map(strval(...), $names));
    }

    public function has(string $server): bool
    {
        return in_array($server, $this->names(), true);
    }

    /**
     * The definition (name, engine, connection, TLS) for a server.
     */
    public function definition(string $server): ServerDefinition
    {
        $catalog = $this->catalogServers();
        if (isset($catalog[$server])) {
            return $catalog[$server];
        }

        /** @var array<string, mixed>|null $raw */
        $raw = $this->config->get("laranail.db-console.servers.{$server}");
        if ($raw === null) {
            throw UnknownServer::named($server);
        }

        return $this->definitionFromConfig($server, $raw);
    }

    /**
     * Resolve a server to its (Engine, AdminConnection) pair, cached per
     * request. Does not probe reachability — call ensureReachable() for that.
     *
     * @return array{0: Engine, 1: AdminConnection}
     */
    public function resolve(string $server): array
    {
        if (isset($this->resolved[$server])) {
            return $this->resolved[$server];
        }

        $definition = $this->definition($server);
        $engine = $this->engines->make($definition->engine);

        if ($this->config->get("database.connections.{$definition->connection}") === null) {
            throw ServerMisconfigured::named(
                $server,
                "admin connection '{$definition->connection}' is not defined in config/database.php",
            );
        }

        $connection = new AdminConnection($server, $this->db->connection($definition->connection));

        return $this->resolved[$server] = [$engine, $connection];
    }

    public function engine(string $server): Engine
    {
        return $this->resolve($server)[0];
    }

    public function connection(string $server): AdminConnection
    {
        return $this->resolve($server)[1];
    }

    /**
     * Probe that the server answers over its admin connection, translating a
     * driver failure into ServerUnreachable.
     */
    public function ensureReachable(string $server): void
    {
        [, $connection] = $this->resolve($server);

        try {
            // A trivial round-trip forces the PDO connection to open and
            // confirms the server answers.
            $connection->underlying()->select('SELECT 1');
        } catch (Throwable $e) {
            throw ServerUnreachable::forServer($server, $e);
        }
    }

    /**
     * Register catalog-backed server definitions (from DbServer rows). Called
     * once the catalog is available; keeps the registry the single resolution
     * point for config and dynamic servers alike.
     *
     * @param  array<string, ServerDefinition>  $servers
     */
    public function withCatalogServers(array $servers): void
    {
        $this->catalogServers = $servers;
        $this->resolved = [];
    }

    /**
     * @return array<string, ServerDefinition>
     */
    private function catalogServers(): array
    {
        return $this->catalogServers ?? [];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function definitionFromConfig(string $server, array $raw): ServerDefinition
    {
        $engineValue = (string) ($raw['engine'] ?? '');
        $engine = EngineType::tryFrom($engineValue);
        if ($engine === null) {
            throw ServerMisconfigured::named(
                $server,
                "invalid engine '{$engineValue}'; expected one of: ".implode(', ', EngineType::values()),
            );
        }

        /** @var array<string, mixed> $tls */
        $tls = (array) ($raw['tls'] ?? []);

        /** @var array<string, mixed> $atRest */
        $atRest = (array) ($raw['at_rest'] ?? []);

        return new ServerDefinition(
            name: $server,
            engine: $engine,
            connection: (string) ($raw['connection'] ?? 'db_console_admin'),
            tls: [
                'enabled' => (bool) ($tls['enabled'] ?? true),
                'verify' => (bool) ($tls['verify'] ?? true),
                'ca' => $this->stringOrNull($tls['ca'] ?? null),
                'cert' => $this->stringOrNull($tls['cert'] ?? null),
                'key' => $this->stringOrNull($tls['key'] ?? null),
            ],
            showAtRestStatus: (bool) ($atRest['show_status'] ?? true),
            editable: false,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
