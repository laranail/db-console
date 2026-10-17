<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security\Contracts;

use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;

/**
 * Confirms the current operator recently proved they are present (a password
 * or passkey confirmation, a one-time code) before a dangerous action. The
 * gate says whether an operator may do something; this says the operator is
 * the one at the keyboard now.
 *
 * Today only NullStepUpVerifier ships, which always passes. The real drivers
 * replace the binding without the call sites changing.
 */
interface StepUpVerifier
{
    /**
     * Return normally when the operator has stepped up for $action at $scope
     * ('server:<name>'); throw a DBConsoleException when they have not.
     */
    public function verify(ConsolePermission $action, ?string $scope = null): void;
}
