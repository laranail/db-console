<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Results;

/**
 * One privilege an account holds, as read live from the server. Postgres
 * rows are structured: the kind of object, its database, the object, the
 * privilege, and where it comes from (`via`: null for a grant to the
 * account itself, `owner` when it owns the object, `PUBLIC`, or the role it
 * inherits it from). An engine whose rows have no such shape (MySQL's SHOW
 * GRANTS, one statement per row) keeps the row's text in line() alone.
 */
final readonly class GrantEntry
{
    private const string UNREADABLE = ' not readable: the admin may not connect to it';

    public function __construct(
        public ?string $kind,
        public ?string $database,
        public ?string $object,
        public ?string $privilege,
        public ?string $via,
        public bool $readable,
        private string $text,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        if (! array_key_exists('privilege_type', $row)) {
            return new self(null, null, null, null, null, true, implode(' ', array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                array_values($row),
            )));
        }

        $field = static fn (string $key): ?string => isset($row[$key]) && is_scalar($row[$key]) && $row[$key] !== ''
            ? (string) $row[$key]
            : null;

        $parts = array_filter(
            [$field('object_type'), $field('database_name'), $field('object_name'), $field('privilege_type')],
            static fn (?string $part): bool => $part !== null,
        );
        $via = $field('via');

        return new self(
            $field('object_type'),
            $field('database_name'),
            $field('object_name'),
            $field('privilege_type'),
            $via,
            true,
            implode(' ', $parts) . ($via !== null ? " via {$via}" : ''),
        );
    }

    /**
     * A database the account has grants in that the admin may not connect
     * to, so they could not be read. Listed rather than left out, so the
     * list does not look complete when it is not.
     */
    public static function unreadable(string $database): self
    {
        return new self('database', $database, $database, null, null, false, "database {$database}" . self::UNREADABLE);
    }

    /**
     * Held by the account itself: granted to it, or as an object's owner.
     */
    public function direct(): bool
    {
        return $this->via === null || $this->via === 'owner';
    }

    public function line(): string
    {
        return $this->text;
    }

    /**
     * @return array{kind: ?string, database: ?string, object: ?string, privilege: ?string, via: ?string, readable: bool, line: string}
     */
    public function toArray(): array
    {
        return [
            'kind'      => $this->kind,
            'database'  => $this->database,
            'object'    => $this->object,
            'privilege' => $this->privilege,
            'via'       => $this->via,
            'readable'  => $this->readable,
            'line'      => $this->text,
        ];
    }
}
