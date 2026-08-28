<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * app_key selected in production without the explicit override. Caught at
 * boot/install, not at runtime, so a misconfigured deploy is stopped before
 * it serves a request.
 */
final class InsecureSecretDriver extends SecretsException
{
    public static function appKeyInProduction(): self
    {
        return new self(
            message: 'the app_key secret driver is not allowed in production without DB_CONSOLE_ALLOW_APPKEY_IN_PROD=true',
            userParams: [],
            context: ['driver' => 'app_key', 'environment' => 'production'],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::InsecureSecretDriver;
    }
}
