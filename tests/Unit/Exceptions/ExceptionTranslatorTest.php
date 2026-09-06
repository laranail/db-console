<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Simtabi\Laranail\DBConsole\Exceptions\UnknownServer;
use Simtabi\Laranail\DBConsole\Exceptions\OperationFailed;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Exceptions\ExceptionTranslator;
use Simtabi\Laranail\DBConsole\Exceptions\AuthenticationFailure;
use Simtabi\Laranail\DBConsole\Exceptions\InsufficientPrivilege;

function pdoExceptionWith(string $sqlState, ?int $errno, string $message = 'driver detail'): PDOException
{
    $e = new PDOException($message);
    $e->errorInfo = [$sqlState, $errno, $message];

    return $e;
}

describe('driver error mapping', function (): void {
    it('maps rejected credentials to AuthenticationFailure', function (string $sqlState, ?int $errno): void {
        $translated = ExceptionTranslator::from(pdoExceptionWith($sqlState, $errno), ['server' => 'prod-mysql']);

        expect($translated)->toBeInstanceOf(AuthenticationFailure::class)
            ->and($translated->userMessage())->toContain('prod-mysql');
    })->with([
        'SQLSTATE 28000'         => ['28000', null],
        'mysql 1045'             => ['HY000', 1045],
        'mysql 1698 auth plugin' => ['HY000', 1698],
    ]);

    it('maps connection failures to ServerUnreachable', function (string $sqlState, ?int $errno): void {
        expect(ExceptionTranslator::from(pdoExceptionWith($sqlState, $errno), ['server' => 'prod-mysql']))
            ->toBeInstanceOf(ServerUnreachable::class);
    })->with([
        'connection class 08006' => ['08006', null],
        'mysql 2002 socket'      => ['HY000', 2002],
        'mysql 2006 gone away'   => ['HY000', 2006],
    ]);

    it('maps privilege denials to InsufficientPrivilege', function (string $sqlState, ?int $errno): void {
        expect(ExceptionTranslator::from(pdoExceptionWith($sqlState, $errno), ['operation' => 'database.create']))
            ->toBeInstanceOf(InsufficientPrivilege::class);
    })->with([
        'mysql 1044 db denied'    => ['42000', 1044],
        'mysql 1142 table denied' => ['42000', 1142],
        'mysql 1227 needs SUPER'  => ['42000', 1227],
        'postgres 42501'          => ['42501', null],
    ]);

    it('maps everything else to OperationFailed, keeping the driver detail only in context', function (): void {
        $translated = ExceptionTranslator::from(pdoExceptionWith('42S01', 1050, 'Table already exists'));

        expect($translated)->toBeInstanceOf(OperationFailed::class)
            ->and($translated->context()['driver_errno'])->toBe(1050)
            ->and($translated->context()['driver_message'])->toContain('already exists')
            ->and($translated->userMessage())->not->toContain('already exists');
    });
});

describe('secret hygiene (must never be weakened)', function (): void {
    it('never copies a QueryException message — it embeds the SQL, which can contain a password', function (): void {
        $password = 'Xk9$mQ2vLpW7#nR4!secret';
        $sql = "CREATE USER 'shop_user'@'%' IDENTIFIED BY '{$password}'";
        $query = new QueryException('admin', $sql, [], pdoExceptionWith('HY000', 1396, 'Operation CREATE USER failed'));

        $translated = ExceptionTranslator::from($query);
        $everything = $translated->getMessage()
            . ' ' . $translated->userMessage()
            . ' ' . json_encode($translated->context());

        // The driver-level message ("Operation CREATE USER failed") is safe
        // and allowed; the executed SQL and the password are not.
        expect($everything)->not->toContain($password)
            ->and($everything)->not->toContain('IDENTIFIED BY');
    });

    it('records only the class name when a QueryException has no plain PDO previous', function (): void {
        $password = 'Xk9$mQ2vLpW7#nR4!secret';
        $query = new QueryException(
            'admin',
            "CREATE USER 'u'@'%' IDENTIFIED BY '{$password}'",
            [],
            new RuntimeException('wrapped'),
        );

        $translated = ExceptionTranslator::from($query);

        expect($translated)->toBeInstanceOf(OperationFailed::class)
            ->and(json_encode($translated->context()))->not->toContain($password)
            ->and($translated->context()['exception'])->toBe(QueryException::class);
    });
});

describe('pass-through and message hygiene', function (): void {
    it('returns DBConsole exceptions untranslated', function (): void {
        $original = UnknownServer::named('prod-mysql');

        expect(ExceptionTranslator::from($original))->toBe($original);
    });

    it('translates non-PDO throwables to OperationFailed without copying the message', function (): void {
        $translated = ExceptionTranslator::from(new RuntimeException('internal detail'));

        expect($translated)->toBeInstanceOf(OperationFailed::class)
            ->and($translated->getMessage())->not->toContain('internal detail');
    });
});

it('every ExceptionCode has a real translated user message', function (): void {
    foreach (ExceptionCode::cases() as $code) {
        $key = 'laranail-db-console::exceptions.' . $code->value;

        expect(__($key))->toBeString()->not->toBe($key, "missing translation for {$code->value}");
    }
});

it('userMessage() resolves through the translator with parameters', function (): void {
    $exception = UnknownServer::named('prod-mysql');

    expect($exception)->toBeInstanceOf(DBConsoleException::class)
        ->and($exception->userMessage())->toBe("No server named 'prod-mysql' is registered.")
        ->and($exception->code())->toBe(ExceptionCode::UnknownServer);
});
