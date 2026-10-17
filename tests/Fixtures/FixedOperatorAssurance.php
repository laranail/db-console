<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;

/**
 * A host's OperatorAssurance with fixed answers: the operator confirmed
 * their identity at $confirmedAt (null: never).
 */
final class FixedOperatorAssurance implements OperatorAssurance
{
    /** @var list<string> */
    public array $askedAbout = [];

    public function __construct(
        private readonly ?DateTimeInterface $confirmedAt,
        private readonly bool $secondFactor = true,
    ) {}

    public static function confirmedNow(): self
    {
        return new self(now()->toImmutable());
    }

    public function hasSecondFactor(Authenticatable $operator): bool
    {
        return $this->secondFactor;
    }

    public function recentlyConfirmedAt(Authenticatable $operator): ?DateTimeInterface
    {
        $this->askedAbout[] = (string) $operator->getAuthIdentifier();

        return $this->confirmedAt;
    }
}
