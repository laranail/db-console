<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * The kinds of resource a create can find already there. The value is the
 * singular of the config section its policy is read from (`accounts`,
 * `databases`).
 */
enum ExistingResourceKind: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Account')]
    case Account = 'account';

    #[Label('Database')]
    case Database = 'database';

    /**
     * The config section holding this kind's `on_existing`.
     */
    public function configSection(): string
    {
        return $this->value . 's';
    }
}
