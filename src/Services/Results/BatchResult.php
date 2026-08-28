<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Results;

/**
 * The outcome of a batch attach/detach: one entry per (user, database)
 * pairing, so the caller can report exactly which pairings succeeded and
 * which didn't (section 15, scenario D). Successful pairings are committed;
 * a failed pairing is rolled back on its own and named here — the batch is
 * per-pairing, not all-or-nothing.
 */
final readonly class BatchResult
{
    /**
     * @param list<array{user: string, host: string, database: string, ok: bool, error: ?string}> $pairings
     */
    public function __construct(public array $pairings) {}

    /**
     * @return list<array{user: string, host: string, database: string, ok: bool, error: ?string}>
     */
    public function succeeded(): array
    {
        return array_values(array_filter($this->pairings, static fn (array $p): bool => $p['ok']));
    }

    /**
     * @return list<array{user: string, host: string, database: string, ok: bool, error: ?string}>
     */
    public function failed(): array
    {
        return array_values(array_filter($this->pairings, static fn (array $p): bool => ! $p['ok']));
    }

    public function allSucceeded(): bool
    {
        return $this->failed() === [];
    }

    public function total(): int
    {
        return count($this->pairings);
    }
}
