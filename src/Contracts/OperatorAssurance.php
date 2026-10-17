<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Contracts;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What the host's authentication knows about an operator, beyond who they
 * are. db-console authenticates nobody: the host owns login, two-factor,
 * recovery codes and passkeys, and answers these two questions about the
 * operator it has already signed in. db-console then checks the answers
 * against what each operation requires (security.assurance), on top of RBAC.
 *
 * Bind your implementation in a service provider; the default binding,
 * NullOperatorAssurance, reports no assurance at all.
 */
interface OperatorAssurance
{
    /**
     * The operator has enrolled in a second factor the host enforces at sign-in.
     */
    public function hasSecondFactor(Authenticatable $operator): bool;

    /**
     * When the operator last re-proved who they are (password confirmation,
     * passkey, one-time code), or null if they have not. db-console compares
     * it with security.step_up.ttl.
     */
    public function recentlyConfirmedAt(Authenticatable $operator): ?DateTimeInterface;
}
