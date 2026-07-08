<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

/**
 * One executable statement plus its display-safe form. Statements that
 * embed secret material (CREATE USER ... IDENTIFIED BY ...) carry a
 * redacted variant; everything shown, logged, or audited uses $redacted,
 * and only the admin connection ever reads $sql.
 */
final readonly class Statement
{
    private function __construct(
        public string $sql,
        public string $redacted,
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
}
