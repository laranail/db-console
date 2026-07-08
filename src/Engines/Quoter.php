<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Enums\EngineType;

/**
 * Per-dialect identifier and string quoting. This is defense in depth on
 * top of the value objects' construction-time allow-list — by the time a
 * value reaches the Quoter it already cannot contain a quote character, so
 * the doubling below can never actually fire on a validated identifier; it
 * exists so the quoting is correct by construction regardless.
 */
final readonly class Quoter
{
    private function __construct(
        private string $open,
        private string $close,
        private string $escapeFrom,
        private string $escapeTo,
    ) {}

    public static function for(EngineType $engine): self
    {
        return match ($engine) {
            EngineType::Mysql, EngineType::Mariadb => new self('`', '`', '`', '``'),
            EngineType::Pgsql, EngineType::Sqlite => new self('"', '"', '"', '""'),
            EngineType::Sqlsrv => new self('[', ']', ']', ']]'),
        };
    }

    /**
     * Quote an identifier (database, table, user name) for this dialect.
     */
    public function identifier(string $value): string
    {
        return $this->open . str_replace($this->escapeFrom, $this->escapeTo, $value) . $this->close;
    }

    /**
     * Quote a string literal (host, charset) with single quotes, doubling
     * embedded single quotes per SQL standard.
     */
    public function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
