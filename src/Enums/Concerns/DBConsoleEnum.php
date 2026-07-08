<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums\Concerns;

use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * Shared behavior for every DBConsole enum: the full laranail/enumerator
 * surface (values/labels/options/casts) with labels resolved from the
 * db-console translation namespace (resources/lang/en/enums.php), falling
 * back to #[Label] attributes and humanized case names.
 */
trait DBConsoleEnum
{
    use HasEnumeratorBehavior;

    public static function translationNamespace(): string
    {
        return 'db-console';
    }
}
