<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * How an SSH tunnel to a database server is opened. The enum is the
 * allow-list: a driver name that is not a case never resolves to anything.
 */
enum TunnelDriverType: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('OpenSSH'), Description('Runs the ssh binary with -N -L. The default; needs ssh on the host.')]
    case OpenSsh = 'openssh';

    #[Label('phpseclib'), Description('Pure PHP, for hosts without an ssh binary. Needs phpseclib/phpseclib ^4.')]
    case Phpseclib = 'phpseclib';
}
