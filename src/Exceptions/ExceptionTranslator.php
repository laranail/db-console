<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Throwable;
use PDOException;
use Illuminate\Database\QueryException;

/**
 * Translates low-level driver/PDO exceptions into the DBConsole hierarchy
 * so nothing raw ever escapes the service layer.
 *
 * Security note: a QueryException message embeds the executed SQL, which
 * can contain a password (CREATE USER ... IDENTIFIED BY ...). This
 * translator therefore never copies a QueryException message anywhere; it
 * reads only the SQLSTATE and driver error number, and takes the driver
 * message from the underlying PDOException, which does not include the
 * statement text.
 */
final class ExceptionTranslator
{
    /** MySQL-family driver error numbers meaning "credentials rejected". */
    private const array AUTH_ERRNOS = [1045, 1698, 1495];

    /** MySQL-family driver error numbers meaning "cannot reach the server". */
    private const array UNREACHABLE_ERRNOS = [2002, 2003, 2006, 2013];

    /** MySQL-family driver error numbers meaning "admin lacks a privilege". */
    private const array PRIVILEGE_ERRNOS = [1044, 1142, 1227, 1370];

    /**
     * @param array<string, mixed> $context extra sanitized context merged into the translated exception
     */
    public static function from(Throwable $e, array $context = []): DBConsoleException
    {
        if ($e instanceof DBConsoleException) {
            return $e;
        }

        $pdo = self::underlyingPdoException($e);
        if (! $pdo instanceof PDOException) {
            // No safe driver-level exception to read. Deliberately record
            // only the class name — a QueryException message embeds the SQL.
            return OperationFailed::atServer(
                [...$context, 'exception' => $e::class],
                $e,
            );
        }

        $sqlState = self::sqlState($pdo);
        $errno = self::driverErrno($pdo);
        $driverContext = [
            ...$context,
            'sqlstate'       => $sqlState,
            'driver_errno'   => $errno,
            'driver_message' => $pdo->getMessage(),
        ];
        $server = isset($context['server']) && is_string($context['server']) ? $context['server'] : 'unknown';

        return match (true) {
            $sqlState === '28000',
            in_array($errno, self::AUTH_ERRNOS, true) => AuthenticationFailure::forServer($server, $e),

            str_starts_with($sqlState, '08'),
            in_array($errno, self::UNREACHABLE_ERRNOS, true) => ServerUnreachable::forServer($server, $e),

            $sqlState === '42501', // Postgres insufficient_privilege
            in_array($errno, self::PRIVILEGE_ERRNOS, true) => InsufficientPrivilege::forOperation(
                is_string($context['operation'] ?? null) ? $context['operation'] : 'unknown',
                $e,
            ),

            default => OperationFailed::atServer($driverContext, $e),
        };
    }

    /**
     * Unwrap to the raw PDO layer. A QueryException extends PDOException
     * but its message embeds the executed SQL, so it is NEVER returned
     * here — only its previous, and only when that is a plain
     * PDOException. Without one, we return null and the caller records
     * just the class name.
     */
    private static function underlyingPdoException(Throwable $e): ?PDOException
    {
        if ($e instanceof QueryException) {
            $previous = $e->getPrevious();

            return ($previous instanceof PDOException && ! $previous instanceof QueryException)
                ? $previous
                : null;
        }

        if ($e instanceof PDOException) {
            return $e;
        }

        $previous = $e->getPrevious();

        return ($previous instanceof PDOException && ! $previous instanceof QueryException)
            ? $previous
            : null;
    }

    private static function sqlState(PDOException $e): string
    {
        $info = $e->errorInfo[0] ?? $e->getCode();

        return is_string($info) ? $info : (string) $info;
    }

    private static function driverErrno(PDOException $e): ?int
    {
        $errno = $e->errorInfo[1] ?? null;

        return is_int($errno) ? $errno : null;
    }
}
