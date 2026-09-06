<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Wizard;

use Closure;

/**
 * One step of a multi-step operation: a forward action and an optional
 * compensating action that undoes ONLY what this step created, and only if
 * safe (e.g. drop a database only if this run created it and it is empty).
 * The compensation is the caller's responsibility to keep safe — the
 * WizardExecutor just runs it in reverse on failure.
 */
final readonly class WizardStep
{
    /**
     * @param Closure(): mixed $forward
     * @param ?Closure(): void $compensate null = nothing to undo (e.g. a failed grant)
     */
    public function __construct(
        public string $label,
        public Closure $forward,
        public ?Closure $compensate = null,
    ) {}

    public static function make(string $label, Closure $forward, ?Closure $compensate = null): self
    {
        return new self($label, $forward, $compensate);
    }
}
