<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Engines\MySqlEngine;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Engines\MariaDbEngine;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Engines\EmptinessProbingEngine;

function engine(): MySqlEngine
{
    return new MySqlEngine;
}

/** @return list<string> */
function sqlOf(iterable $list): array
{
    $out = [];
    foreach ($list as $statement) {
        $out[] = $statement->sql;
    }

    return $out;
}

describe('capabilities', function (): void {
    it('reports the MySQL account model honestly', function (): void {
        $caps = engine()->capabilities();

        expect($caps->canCreateAccount)->toBeTrue()
            ->and($caps->canScopeAccountsByHost)->toBeTrue()
            ->and($caps->canGrantTableLevel)->toBeTrue()
            ->and($caps->canRotatePassword)->toBeTrue()
            ->and($caps->encryption->canRequireTlsOnAccount)->toBeTrue();
    });

    it('identifies as mysql, and MariaDB as mariadb', function (): void {
        expect(engine()->type())->toBe(EngineType::Mysql)
            ->and((new MariaDbEngine)->type())->toBe(EngineType::Mariadb);
    });
});

describe('exact database statements', function (): void {
    it('creates a database with charset and collation', function (): void {
        $sql = sqlOf(engine()->createDatabase(new DbName('shop_prod'), new Charset('utf8mb4', 'utf8mb4_unicode_ci')));

        expect($sql)->toBe([
            "CREATE DATABASE `shop_prod` CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
        ]);
    });

    it('creates a database with charset only', function (): void {
        $sql = sqlOf(engine()->createDatabase(new DbName('shop_prod'), new Charset('utf8mb4')));

        expect($sql)->toBe(["CREATE DATABASE `shop_prod` CHARACTER SET 'utf8mb4'"]);
    });

    it('drops a database', function (): void {
        expect(sqlOf(engine()->dropDatabase(new DbName('shop_prod'))))
            ->toBe(['DROP DATABASE `shop_prod`']);
    });

    it('lists databases', function (): void {
        expect(sqlOf(engine()->listDatabases()))->toBe(['SHOW DATABASES']);
    });
});

describe('exact account statements', function (): void {
    it('creates an account at a host', function (): void {
        $statements = engine()->createAccount(new Username('shop_user'), new Host('10.0.%'), new Password(testPassword()))->all();

        expect($statements[0]->sql)->toBe("CREATE USER 'shop_user'@'10.0.%' IDENTIFIED BY '" . testPassword() . "'");
    });

    it('redacts the password in the display form (never the real value)', function (): void {
        $statements = engine()->createAccount(new Username('shop_user'), new Host('%'), new Password(testPassword()))->all();

        expect($statements[0]->redacted)->toBe("CREATE USER 'shop_user'@'%' IDENTIFIED BY '[redacted]'")
            ->and($statements[0]->redacted)->not->toContain(testPassword());
    });

    it('drops an account', function (): void {
        expect(sqlOf(engine()->dropAccount(new Username('shop_user'), new Host('localhost'))))
            ->toBe(["DROP USER 'shop_user'@'localhost'"]);
    });

    it('sets a password, redacting the display form', function (): void {
        $statements = engine()->setPassword(new Username('shop_user'), new Host('%'), new Password(testPassword()))->all();

        expect($statements[0]->sql)->toBe("ALTER USER 'shop_user'@'%' IDENTIFIED BY '" . testPassword() . "'")
            ->and($statements[0]->redacted)->toBe("ALTER USER 'shop_user'@'%' IDENTIFIED BY '[redacted]'");
    });

    it('shows grants for an account', function (): void {
        expect(sqlOf(engine()->showGrants(new Username('shop_user'), new Host('%'))))
            ->toBe(["SHOW GRANTS FOR 'shop_user'@'%'"]);
    });
});

describe('exact grant statements with preset translation', function (): void {
    it('translates ReadWrite to the MySQL vocabulary, database-scoped', function (): void {
        $sql = sqlOf(engine()->grant(
            PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
            new DbName('shop_prod'),
            new Username('shop_user'),
            new Host('10.0.%'),
        ));

        expect($sql)->toBe([
            "GRANT SELECT, SHOW VIEW, INSERT, UPDATE, DELETE ON `shop_prod`.* TO 'shop_user'@'10.0.%'",
        ]);
    });

    it('translates AppStandard', function (): void {
        $sql = sqlOf(engine()->grant(
            PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
            new DbName('shop_prod'),
            new Username('app'),
            new Host('%'),
        ));

        expect($sql[0])->toBe(
            'GRANT SELECT, SHOW VIEW, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, '
            . "CREATE TEMPORARY TABLES, EXECUTE ON `shop_prod`.* TO 'app'@'%'",
        );
    });

    it('scopes Full to one database (never *.*)', function (): void {
        $sql = sqlOf(engine()->grant(
            PrivilegeSet::fromPreset(PrivilegePreset::Full),
            new DbName('shop_prod'),
            new Username('owner'),
            new Host('%'),
        ));

        expect($sql[0])->toContain('ON `shop_prod`.*')
            ->and($sql[0])->not->toContain('*.*')
            ->and($sql[0])->not->toContain('GRANT OPTION');
    });

    it('revokes a custom set', function (): void {
        $sql = sqlOf(engine()->revoke(
            PrivilegeSet::custom(['SELECT', 'insert']),
            new DbName('shop_prod'),
            new Username('shop_user'),
            new Host('%'),
        ));

        expect($sql)->toBe([
            "REVOKE SELECT, INSERT ON `shop_prod`.* FROM 'shop_user'@'%'",
        ]);
    });
});

describe('recreating an account from its stored hash', function (): void {
    it('feeds MySQL the hash as a hex literal, since caching_sha2 hashes are binary', function (): void {
        $statements = iterator_to_array(engine()->createAccountWithAuth(
            new Username('shop_user'),
            new Host('%'),
            'caching_sha2_password',
            '0x2441243030352400',
        ));

        expect($statements[0]->sql)->toBe("CREATE USER 'shop_user'@'%' IDENTIFIED WITH `caching_sha2_password` AS 0x2441243030352400")
            ->and($statements[0]->redacted)->not->toContain('0x24');
    });

    it('feeds MariaDB the hash as a quoted string, the only form its grammar accepts after AS', function (): void {
        // '*94BDCEBE19083CE2A1F959FD02F964C7AF4CFC29' is mysql_native_password's
        // stored form of 'test'; readAuthentication returns it hex-encoded.
        $statements = iterator_to_array((new MariaDbEngine)->createAccountWithAuth(
            new Username('shop_user'),
            new Host('%'),
            'mysql_native_password',
            '0x' . strtoupper(bin2hex('*94BDCEBE19083CE2A1F959FD02F964C7AF4CFC29')),
        ));

        expect($statements[0]->sql)->toBe("CREATE USER 'shop_user'@'%' IDENTIFIED WITH `mysql_native_password` AS '*94BDCEBE19083CE2A1F959FD02F964C7AF4CFC29'")
            ->and($statements[0]->redacted)->not->toContain('94BDCEBE');
    });

    it('refuses a MySQL hash literal that is not hex, since it is spliced into the statement', function (string $literal): void {
        expect(fn (): StatementList => engine()->createAccountWithAuth(
            new Username('shop_user'),
            new Host('%'),
            'caching_sha2_password',
            $literal,
        ))->toThrow(InvalidArgumentException::class);
    })->with(["0x41' OR '1", '0x4', 'AB12', '0x41; DROP USER root']);

    it('refuses a MariaDB hash literal that is not hex', function (): void {
        expect(fn (): StatementList => (new MariaDbEngine)->createAccountWithAuth(
            new Username('shop_user'),
            new Host('%'),
            'mysql_native_password',
            "0x41' OR '1",
        ))->toThrow(InvalidArgumentException::class);
    });
});

// A database holding only a stored procedure, an event or a trigger is not
// empty, and a rollback that thought so would drop the procedure with it.
it('counts every kind of object a database can hold for the emptiness check', function (string $class): void {
    $engine = new $class;

    expect($engine)->toBeInstanceOf(EmptinessProbingEngine::class);

    $statements = $engine->countUserObjects(new DbName('shop_prod'))->all();

    expect($statements)->toHaveCount(1)
        ->and($statements[0]->database)->toBeNull();   // information_schema reads from anywhere

    $sql = $statements[0]->sql;
    foreach (['TABLES' => 'TABLE_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA'] as $table => $column) {
        expect($sql)->toContain("information_schema.{$table} WHERE {$column} = 'shop_prod'");
    }
})->with([MySqlEngine::class, MariaDbEngine::class]);
