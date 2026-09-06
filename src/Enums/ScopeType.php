<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * RBAC scope granularity, widest to narrowest. A wider scope covers every
 * narrower one beneath it: global covers server covers database — never
 * the reverse.
 */
enum ScopeType: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Global')]
    case Global = 'global';

    #[Label('Server')]
    case Server = 'server';

    #[Label('Database')]
    case Database = 'database';

    /**
     * Whether this scope type is at least as wide as the given one.
     */
    public function covers(self $other): bool
    {
        return $this->width() <= $other->width();
    }

    private function width(): int
    {
        return match ($this) {
            self::Global   => 0,
            self::Server   => 1,
            self::Database => 2,
        };
    }
}
