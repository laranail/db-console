<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

/**
 * One executable statement plus its display-safe form. Statements that
 * embed secret material (CREATE USER ... IDENTIFIED BY ...) carry a
 * redacted variant; everything shown, logged, or audited uses $redacted,
 * and only the admin connection ever reads $sql.
 *
 * $database names the database the statement must run in, or is null for
 * the admin connection's own. Postgres needs it: GRANT ... ON ALL TABLES IN
 * SCHEMA, ALTER DEFAULT PRIVILEGES and REASSIGN OWNED act on the database
 * the session is connected to, so the same SQL means something else on the
 * wrong connection. AdminConnection refuses a statement scoped elsewhere;
 * ScopedConnections opens the connection it needs.
 */
final readonly class Statement
{
    private function __construct(
        public string $sql,
        public string $redacted,
        public ?string $database = null,
    ) {}

    /**
     * A statement with no secret material: display form equals the SQL.
     */
    public static function plain(string $sql): self
    {
        return new self($sql, $sql);
    }

    /**
     * A statement embedding secret material, with an explicit redacted
     * display form.
     */
    public static function sensitive(string $sql, string $redacted): self
    {
        return new self($sql, $redacted);
    }

    /**
     * The same statement, to be run inside $database rather than on the
     * admin connection's own database.
     */
    public function inDatabase(DbName $database): self
    {
        return new self($this->sql, $this->redacted, $database->value);
    }
}
