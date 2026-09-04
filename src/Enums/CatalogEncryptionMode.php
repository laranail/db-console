<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * How the catalog itself is protected at rest: encrypted columns always;
 * whole-file SQLCipher additionally when the extension and key are present.
 */
enum CatalogEncryptionMode: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Encrypted columns')]
    case Columns = 'columns';

    #[Label('Whole-file (SQLCipher) + encrypted columns')]
    case WholeFile = 'whole_file';
}
