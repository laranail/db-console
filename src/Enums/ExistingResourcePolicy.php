<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * What a create does when the account or database it was asked to create is
 * already there. Resolved most specific first: the call, then the server's
 * `<kind>s.on_existing`, then the global `<kind>s.on_existing`, default
 * Refuse. The two adopting cases act on something db-console did not create
 * and that something else may be using, so each needs its own permission.
 */
enum ExistingResourcePolicy: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Refuse'), Description('Change nothing and report it. Safe: the existing resource keeps working as it did.')]
    case Refuse = 'refuse';

    #[Label('Adopt'), Description('Start managing it as it is, without touching its password. Risk: db-console now manages a resource something else may own; needs resource.adopt.')]
    case Adopt = 'adopt';

    #[Label('Adopt and apply the password'), Description('Adopt it, then replace its password with the one supplied. Risk: anything already logging in with the old password stops working; needs resource.adopt, account.rotate and step-up.')]
    case AdoptAndApply = 'adopt_and_apply';

    /**
     * The default when nothing more specific is configured.
     */
    public static function default(): self
    {
        return self::Refuse;
    }

    /**
     * The cases that make sense for $kind, in order of increasing risk.
     *
     * @return list<self>
     */
    public static function casesFor(ExistingResourceKind $kind): array
    {
        return $kind === ExistingResourceKind::Database
            ? [self::Refuse, self::Adopt]
            : self::cases();
    }

    /**
     * The policy for $kind: a database has no password to apply, so
     * AdoptAndApply adopts it.
     */
    public function forKind(ExistingResourceKind $kind): self
    {
        return $kind === ExistingResourceKind::Database && $this === self::AdoptAndApply ? self::Adopt : $this;
    }

    public function adopts(): bool
    {
        return $this !== self::Refuse;
    }
}
