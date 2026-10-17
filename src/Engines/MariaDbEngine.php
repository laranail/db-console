<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Override;
use InvalidArgumentException;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * MariaDB shares MySQL's account-management dialect for everything
 * DBConsole does, except one form: after IDENTIFIED WITH <plugin> AS,
 * MariaDB's grammar takes only a quoted string, so the 0x... hash literal
 * MySQL accepts is a syntax error there. MariaDB's stored hashes are text
 * (mysql_native_password's '*<hex>', ed25519's base64), so the hex read by
 * readAuthentication decodes to a string the Quoter can quote.
 */
final class MariaDbEngine extends MySqlEngine
{
    #[Override]
    public function type(): EngineType
    {
        return EngineType::Mariadb;
    }

    #[Override]
    public function createAccountWithAuth(Username $u, Host $h, string $plugin, string $hashLiteral): StatementList
    {
        if (preg_match('/^0x((?:[0-9A-Fa-f]{2})*)$/', $hashLiteral, $match) !== 1) {
            throw new InvalidArgumentException('An authentication hash must be a 0x... hex literal.');
        }

        $q = Quoter::for($this->type());
        $account = $this->account($q, $u, $h);
        $pluginQuoted = $q->identifier($plugin);

        return new StatementList(
            Statement::sensitive(
                sql: "CREATE USER {$account} IDENTIFIED WITH {$pluginQuoted} AS " . $q->literal((string) hex2bin($match[1])),
                redacted: "CREATE USER {$account} IDENTIFIED WITH {$pluginQuoted} AS [redacted]",
            ),
        );
    }
}
