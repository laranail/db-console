<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * The canonical, abstract privilege allow-list. Every case is a database- or
 * table-scoped privilege; self-escalating and server-wide privileges are not
 * representable here — they live in ForbiddenPrivilege, and the guard reads
 * that single source via Privilege::forbidden().
 *
 * Engines translate these abstract cases to their own dialect vocabulary in
 * one place (the engine class).
 */
enum Privilege: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('SELECT')]
    case Select = 'select';

    #[Label('INSERT')]
    case Insert = 'insert';

    #[Label('UPDATE')]
    case Update = 'update';

    #[Label('DELETE')]
    case Delete = 'delete';

    #[Label('CREATE')]
    case Create = 'create';

    #[Label('ALTER')]
    case Alter = 'alter';

    #[Label('DROP')]
    case Drop = 'drop';

    #[Label('INDEX')]
    case Index = 'index';

    #[Label('REFERENCES')]
    case References = 'references';

    #[Label('CREATE TEMPORARY TABLES')]
    case CreateTemporaryTables = 'create_temporary_tables';

    #[Label('LOCK TABLES')]
    case LockTables = 'lock_tables';

    #[Label('EXECUTE')]
    case Execute = 'execute';

    #[Label('CREATE VIEW')]
    case CreateView = 'create_view';

    #[Label('SHOW VIEW')]
    case ShowView = 'show_view';

    #[Label('CREATE ROUTINE')]
    case CreateRoutine = 'create_routine';

    #[Label('ALTER ROUTINE')]
    case AlterRoutine = 'alter_routine';

    #[Label('EVENT')]
    case Event = 'event';

    #[Label('TRIGGER')]
    case Trigger = 'trigger';

    /**
     * The hard-block list. This is the single source the PrivilegeSet guard
     * reads, so the guard and the enum cannot drift apart.
     *
     * @return list<ForbiddenPrivilege>
     */
    public static function forbidden(): array
    {
        return ForbiddenPrivilege::cases();
    }

    /**
     * Parse loose operator input ("SELECT", "create view", "LOCK-TABLES")
     * into a case, or null when the token is not on the allow-list.
     */
    public static function tryFromLoose(string $input): ?self
    {
        return self::tryFrom(self::normalizeToken($input));
    }

    /**
     * @internal shared by Privilege and ForbiddenPrivilege parsing
     */
    public static function normalizeToken(string $input): string
    {
        $token = strtolower(trim($input));

        return (string) preg_replace('/[\s\-]+/', '_', $token);
    }
}
