<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

/**
 * The step-up proof the current request or command presented, bound as a
 * scoped instance so it never outlives one request, queued job, or Octane
 * tick. The API guard fills it from the `X-DB-Console-OTP` header and commands
 * from `--otp`, which keeps the service signatures free of it.
 *
 * Once a proof verifies it is not checked again in the same scope: a recovery
 * code is single-use, so a second check of the same code would fail.
 */
final class StepUpProof
{
    private ?string $otp = null;

    private bool $satisfied = false;

    public function present(?string $otp): void
    {
        $otp = $otp === null ? null : trim($otp);
        $this->otp = $otp === '' ? null : $otp;
    }

    public function otp(): ?string
    {
        return $this->otp;
    }

    public function markSatisfied(): void
    {
        $this->satisfied = true;
    }

    public function isSatisfied(): bool
    {
        return $this->satisfied;
    }
}
