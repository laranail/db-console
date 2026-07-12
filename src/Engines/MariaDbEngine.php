<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Override;
use Simtabi\Laranail\DBConsole\Enums\EngineType;

/**
 * MariaDB shares MySQL's account-management dialect for everything
 * DBConsole does; only the engine identity differs.
 */
final class MariaDbEngine extends MySqlEngine
{
    #[Override]
    public function type(): EngineType
    {
        return EngineType::Mariadb;
    }
}
