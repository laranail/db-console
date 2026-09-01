<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * An ordered list of statements produced by an engine. Immutable; only
 * engines construct these.
 *
 * @implements IteratorAggregate<int, Statement>
 */
final readonly class StatementList implements Countable, IteratorAggregate
{
    /** @var list<Statement> */
    private array $statements;

    public function __construct(Statement ...$statements)
    {
        $this->statements = array_values($statements);
    }

    /**
     * @return list<Statement>
     */
    public function all(): array
    {
        return $this->statements;
    }

    /**
     * The display-safe SQL, one entry per statement.
     *
     * @return list<string>
     */
    public function toRedactedArray(): array
    {
        return array_map(static fn (Statement $s): string => $s->redacted, $this->statements);
    }

    public function count(): int
    {
        return count($this->statements);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->statements);
    }
}
