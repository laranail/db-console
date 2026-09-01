<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Engines\MariaDbEngine;
use Simtabi\Laranail\DBConsole\Engines\MySqlEngine;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;

const PW = 'Xk9$mQ2vLpW7#nR4t!';

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
        $statements = engine()->createAccount(new Username('shop_user'), new Host('10.0.%'), new Password(PW))->all();

        expect($statements[0]->sql)->toBe("CREATE USER 'shop_user'@'10.0.%' IDENTIFIED BY '".PW."'");
    });

    it('redacts the password in the display form (never the real value)', function (): void {
        $statements = engine()->createAccount(new Username('shop_user'), new Host('%'), new Password(PW))->all();

        expect($statements[0]->redacted)->toBe("CREATE USER 'shop_user'@'%' IDENTIFIED BY '[redacted]'")
            ->and($statements[0]->redacted)->not->toContain(PW);
    });

    it('drops an account', function (): void {
        expect(sqlOf(engine()->dropAccount(new Username('shop_user'), new Host('localhost'))))
            ->toBe(["DROP USER 'shop_user'@'localhost'"]);
    });

    it('sets a password, redacting the display form', function (): void {
        $statements = engine()->setPassword(new Username('shop_user'), new Host('%'), new Password(PW))->all();

        expect($statements[0]->sql)->toBe("ALTER USER 'shop_user'@'%' IDENTIFIED BY '".PW."'")
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
            ."CREATE TEMPORARY TABLES, EXECUTE ON `shop_prod`.* TO 'app'@'%'",
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
