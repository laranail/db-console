<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Servers;

use Throwable;
use Illuminate\Database\DatabaseManager;
use Simtabi\Laranail\DBConsole\Enums\Hosting;
use Simtabi\Laranail\DBConsole\Engines\Engine;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Domain\Capabilities;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Domain\HostingProfile;
use Simtabi\Laranail\DBConsole\Engines\EngineFactory;
use Simtabi\Laranail\DBConsole\Exceptions\UnknownServer;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;
use Simtabi\Laranail\DBConsole\Exceptions\ServerMisconfigured;
use Simtabi\Laranail\DBConsole\Encryption\TlsConnectionOptions;

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
        private readonly TlsConnectionOptions $tls,
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

        /** @var array<string, mixed>|null $connectionConfig */
        $connectionConfig = $this->config->get("database.connections.{$definition->connection}");
        if ($connectionConfig === null) {
            throw ServerMisconfigured::named(
                $server,
                "admin connection '{$definition->connection}' is not defined in config/database.php",
            );
        }

        $this->applyTls($definition, $connectionConfig);

        $connection = new AdminConnection($server, $this->db->connection($definition->connection));

        return $this->resolved[$server] = [$engine, $connection];
    }

    /**
     * What the server's hosting means for its engine.
     */
    public function hosting(string $server): HostingProfile
    {
        $definition = $this->definition($server);

        return $definition->hosting->profile($definition->engine);
    }

    /**
     * The engine's capabilities with the server's hosting merged in. Prefer
     * this to engine()->capabilities(), which knows nothing of where the
     * server runs.
     */
    public function capabilities(string $server): Capabilities
    {
        return $this->engine($server)->capabilities()->withHosting($this->hosting($server));
    }

    /**
     * The host a new account on this server gets when none is given: the
     * hosting's (`%` on a managed MySQL), else `accounts.default_host`.
     */
    public function defaultAccountHost(string $server): string
    {
        return $this->hosting($server)->defaultAccountHost
            ?? (string) $this->config->get('laranail.db-console.accounts.default_host', 'localhost');
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
     * @param array<string, ServerDefinition> $servers
     */
    public function withCatalogServers(array $servers): void
    {
        $this->catalogServers = $servers;
        $this->resolved = [];
    }

    /**
     * Write the server's TLS options into its admin connection config before
     * the connection is made. Written to config rather than passed to
     * connectUsing() so everything else reading that connection (the backup
     * service copies it) sees the same settings. A connection already opened
     * without them is purged so the next use reconnects with TLS.
     *
     * @param array<string, mixed> $connectionConfig
     */
    private function applyTls(ServerDefinition $definition, array $connectionConfig): void
    {
        $withTls = $this->tls->apply($definition, $connectionConfig);
        if ($withTls === $connectionConfig) {
            return;
        }

        $this->config->set("database.connections.{$definition->connection}", $withTls);

        if (array_key_exists($definition->connection, $this->db->getConnections())) {
            $this->db->purge($definition->connection);
        }
    }

    /**
     * @return array<string, ServerDefinition>
     */
    private function catalogServers(): array
    {
        return $this->catalogServers ?? [];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function definitionFromConfig(string $server, array $raw): ServerDefinition
    {
        $engineValue = (string) ($raw['engine'] ?? '');
        $engine = EngineType::tryFrom($engineValue);
        if ($engine === null) {
            throw ServerMisconfigured::named(
                $server,
                "invalid engine '{$engineValue}'; expected one of: " . implode(', ', EngineType::values()),
            );
        }

        /** @var array<string, mixed> $tls */
        $tls = (array) ($raw['tls'] ?? []);

        /** @var array<string, mixed> $atRest */
        $atRest = (array) ($raw['at_rest'] ?? []);

        // Absent means self-hosted, so an existing config is unchanged. An
        // unknown value fails rather than quietly becoming self-hosted, which
        // would expose the provider's accounts and dump instead of using PITR.
        $hostingValue = (string) ($raw['hosting'] ?? Hosting::SelfHosted->value);
        $hosting = Hosting::tryFrom($hostingValue) ?? throw ServerMisconfigured::named(
            $server,
            "invalid hosting '{$hostingValue}'; expected one of: " . implode(', ', Hosting::values()),
        );

        return new ServerDefinition(
            name: $server,
            engine: $engine,
            connection: (string) ($raw['connection'] ?? 'db_console_admin'),
            tls: [
                'enabled' => (bool) ($tls['enabled'] ?? true),
                'verify'  => (bool) ($tls['verify'] ?? true),
                'ca'      => $this->stringOrNull($tls['ca'] ?? null),
                'cert'    => $this->stringOrNull($tls['cert'] ?? null),
                'key'     => $this->stringOrNull($tls['key'] ?? null),
            ],
            showAtRestStatus: (bool) ($atRest['show_status'] ?? true),
            editable: false,
            hosting: $hosting,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
