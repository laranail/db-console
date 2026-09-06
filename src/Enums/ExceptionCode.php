<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * The closed set of machine-readable error codes. Every DBConsoleException
 * carries exactly one; the code doubles as the translation key for the
 * exception's user-safe message (laranail-db-console::exceptions.<code>).
 */
enum ExceptionCode: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    // Domain
    case IdentifierInvalid = 'identifier.invalid';
    case PasswordWeak = 'password.weak';
    case PrivilegeUnknown = 'privilege.unknown';
    case PrivilegeForbidden = 'privilege.forbidden';

    // Engine
    case UnsupportedOperation = 'engine.unsupported_operation';
    case StatementBuildFailure = 'engine.statement_build_failure';

    // Connection
    case ServerUnreachable = 'server.unreachable';
    case AuthenticationFailed = 'server.authentication_failed';
    case InsufficientPrivilege = 'server.insufficient_privilege';

    // Execution
    case OperationFailed = 'operation.failed';
    case RollbackFailed = 'rollback.failed';

    // Authorization
    case NotAuthorized = 'not_authorized';

    // Secrets
    case SecretUnavailable = 'secret.unavailable';
    case SecretDriverMisconfigured = 'secret.driver_misconfigured';
    case InsecureSecretDriver = 'secret.insecure_driver';

    // Registry
    case UnknownServer = 'server.unknown';
    case ServerMisconfigured = 'server.misconfigured';

    /**
     * The HTTP status the REST API returns for this failure. Validation and
     * input problems are 422; authorization is 403; missing servers are 404;
     * upstream/server failures are 502; everything else is a 500. The API maps
     * the exception to this so callers get a meaningful code, never a raw 500.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::NotAuthorized => 403,
            self::UnknownServer => 404,
            self::IdentifierInvalid,
            self::PasswordWeak,
            self::PrivilegeUnknown,
            self::PrivilegeForbidden,
            self::UnsupportedOperation => 422,
            self::ServerUnreachable,
            self::AuthenticationFailed,
            self::InsufficientPrivilege,
            self::OperationFailed => 502,
            default               => 500,
        };
    }
}
