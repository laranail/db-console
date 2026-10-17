<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * An engine whose statements depend on the server's version, for syntax an
 * older server rejects. Postgres is that engine: LOCALE_PROVIDER, which an
 * ICU cluster needs to create a database in the C locale, is a syntax error
 * before 15. A caller reads the version and asks for an engine built for it;
 * without one the engine writes what every supported version accepts.
 */
interface ServerVersionAwareEngine
{
    /**
     * A read returning the server's version as one integer.
     */
    public function serverVersion(): StatementList;

    /**
     * The same engine, writing statements for $version. Does not change
     * this one.
     */
    public function forServerVersion(int $version): static;
}
