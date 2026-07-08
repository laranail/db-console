<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Results;

use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * The result of a service operation: its outcome, the operation type, and a
 * small, secret-free payload describing the live truth (the created object's
 * identity, an "already exists" flag, a generated password shown exactly
 * once via generatedPassword). Every caller — API, CLI, UI — consumes this.
 */
final readonly class OperationResult
{
    /**
     * @param  array<string, scalar|null>  $data  secret-free identity/detail
     */
    public function __construct(
        public OperationType $operation,
        public OperationOutcome $outcome,
        public string $server,
        public array $data = [],
        public bool $alreadyExisted = false,
        private ?string $generatedPassword = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $data  secret-free identity/detail
     */
    public static function succeeded(
        OperationType $operation,
        string $server,
        array $data = [],
        bool $alreadyExisted = false,
        ?string $generatedPassword = null,
    ): self {
        return new self($operation, OperationOutcome::Succeeded, $server, $data, $alreadyExisted, $generatedPassword);
    }

    /**
     * The generated password. It lives ONLY on this transient result and the
     * create response — it is never written to the catalog, the log, the
     * audit trail, or a webhook — so it is genuinely "shown once": a later
     * read of the account can never return it because it was never stored.
     */
    public function takeGeneratedPassword(): ?string
    {
        return $this->generatedPassword;
    }

    public function hasGeneratedPassword(): bool
    {
        return $this->generatedPassword !== null;
    }
}
