<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * Curated privilege presets so nobody hand-writes grant strings. Presets are
 * abstract; each engine translates the resolved Privilege list to its own
 * vocabulary. "Full" means all allow-listed privileges ON THE TARGET
 * DATABASE — never server-wide.
 */
enum PrivilegePreset: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Read only'), Description('Read data and views.')]
    case ReadOnly = 'read_only';

    #[Label('Read / write'), Description('Read plus insert, update, and delete.')]
    case ReadWrite = 'read_write';

    #[Label('App standard'), Description('Read/write plus create, alter, index, temporary tables, and execute.')]
    case AppStandard = 'app_standard';

    #[Label('Full'), Description('All privileges on the target database — never server-wide.')]
    case Full = 'full';

    #[Label('Custom'), Description('A hand-picked selection, validated against the allow-list.')]
    case Custom = 'custom';

    /**
     * The resolved privilege list for this preset. Custom resolves to an
     * empty list — a custom set must be built explicitly through
     * PrivilegeSet::custom().
     *
     * @return list<Privilege>
     */
    public function privileges(): array
    {
        return match ($this) {
            self::ReadOnly => [
                Privilege::Select,
                Privilege::ShowView,
            ],
            self::ReadWrite => [
                Privilege::Select,
                Privilege::ShowView,
                Privilege::Insert,
                Privilege::Update,
                Privilege::Delete,
            ],
            self::AppStandard => [
                Privilege::Select,
                Privilege::ShowView,
                Privilege::Insert,
                Privilege::Update,
                Privilege::Delete,
                Privilege::Create,
                Privilege::Alter,
                Privilege::Index,
                Privilege::CreateTemporaryTables,
                Privilege::Execute,
            ],
            self::Full => Privilege::cases(),
            self::Custom => [],
        };
    }
}
