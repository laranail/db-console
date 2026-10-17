<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Contracts;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What the host application knows about how strongly the current operator
 * has proved who they are. Authentication stays with the host: it implements
 * this from its own two-factor and password/passkey confirmation, and
 * db-console only enforces policy on the answers.
 */
interface OperatorAssurance
{
    /**
     * Whether the operator has a confirmed second factor.
     */
    public function hasSecondFactor(Authenticatable $operator): bool;

    /**
     * When the operator last confirmed their identity (a password, passkey or
     * one-time code), or null when the host has no record of it.
     */
    public function recentlyConfirmedAt(Authenticatable $operator): ?DateTimeInterface;
}
