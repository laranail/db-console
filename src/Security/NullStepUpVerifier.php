<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Security\Contracts\StepUpVerifier;

/**
 * Asks for no step-up. The default until a real driver is configured, so the
 * permission checks alone decide.
 */
final readonly class NullStepUpVerifier implements StepUpVerifier
{
    public function verify(ConsolePermission $action, ?string $scope = null): void {}
}
