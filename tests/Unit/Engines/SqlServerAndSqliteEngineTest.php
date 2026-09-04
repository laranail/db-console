<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Engines\SqliteEngine;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Engines\SqlServerEngine;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Exceptions\UnsupportedOperation;

/** @return list<string> */
function sqlOfList(iterable $list): array
{
    $out = [];
    foreach ($list as $s) {
        $out[] = $s->sql;
    }

    return $out;
}

describe('SQL Server: login + user two-step, bracket quoting', function (): void {
    $engine = new SqlServerEngine;

    it('brackets identifiers', function () use ($engine): void {
        expect(sqlOfList($engine->createDatabase(new DbName('shop_prod'), new Charset('utf8'))))
            ->toBe(['CREATE DATABASE [shop_prod]']);
    });

    it('creates a login AND a user (two statements), redacting the password', function () use ($engine): void {
        $statements = $engine->createAccount(new Username('app_user'), new Host('%'), new Password('Xk9$mQ2vLpW7#nR4t!'))->all();

        expect($statements)->toHaveCount(2)
            ->and($statements[0]->sql)->toBe('CREATE LOGIN [app_user] WITH PASSWORD = \'Xk9$mQ2vLpW7#nR4t!\'')
            ->and($statements[0]->redacted)->toBe('CREATE LOGIN [app_user] WITH PASSWORD = \'[redacted]\'')
            ->and($statements[1]->sql)->toBe('CREATE USER [app_user] FOR LOGIN [app_user]');
    });

    it('drops the user and the login', function () use ($engine): void {
        expect(sqlOfList($engine->dropAccount(new Username('app_user'), new Host('%'))))
            ->toBe(['DROP USER [app_user]', 'DROP LOGIN [app_user]']);
    });

    it('grants scoped privileges to the user', function () use ($engine): void {
        $sql = sqlOfList($engine->grant(
            PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
            new DbName('shop_prod'),
            new Username('app_user'),
            new Host('%'),
        ));

        expect($sql[0])->toContain('GRANT ')
            ->and($sql[0])->toContain('TO [app_user]')
            ->and($sql[0])->not->toContain('sysadmin');
    });
});

describe('SQLite: honest capability degradation (section 13)', function (): void {
    $engine = new SqliteEngine;

    it('reports no account or privilege concept', function () use ($engine): void {
        $caps = $engine->capabilities();

        expect($engine->type())->toBe(EngineType::Sqlite)
            ->and($caps->canCreateDatabase)->toBeTrue()
            ->and($caps->canCreateAccount)->toBeFalse()
            ->and($caps->canScopeAccountsByHost)->toBeFalse()
            ->and($caps->canGrantTableLevel)->toBeFalse()
            ->and($caps->canRotatePassword)->toBeFalse()
            ->and($caps->accountModelNote)->toContain('file permissions');
    });

    it('produces no CREATE DATABASE statement (a database is a file)', function () use ($engine): void {
        expect($engine->createDatabase(new DbName('shop'), new Charset('utf8')))->toHaveCount(0)
            ->and($engine->dropDatabase(new DbName('shop')))->toHaveCount(0)
            ->and($engine->listDatabases())->toHaveCount(0);
    });

    it('throws UnsupportedOperation for every account/grant operation, honestly', function () use ($engine): void {
        expect(fn (): StatementList => $engine->createAccount(new Username('u'), new Host('%'), new Password('Xk9$mQ2vLpW7#nR4t!')))
            ->toThrow(UnsupportedOperation::class)
            ->and(fn (): StatementList => $engine->dropAccount(new Username('u'), new Host('%')))->toThrow(UnsupportedOperation::class)
            ->and(fn (): StatementList => $engine->setPassword(new Username('u'), new Host('%'), new Password('Xk9$mQ2vLpW7#nR4t!')))->toThrow(UnsupportedOperation::class)
            ->and(fn (): StatementList => $engine->grant(PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly), new DbName('d'), new Username('u'), new Host('%')))->toThrow(UnsupportedOperation::class)
            ->and(fn (): StatementList => $engine->showGrants(new Username('u'), new Host('%')))->toThrow(UnsupportedOperation::class);
    });
});
