<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;

/**
 * A starting point for hosts on Laravel's own password confirmation: reads the
 * standard `auth.password_confirmed_at` session key that the `password.confirm`
 * flow writes (whatever confirmed it: a password, or a passkey in
 * laranail/authkit-preset). It knows nothing about a second factor, so it
 * reports none; decorate it, or write your own, to add that.
 *
 * Not bound by default. Bind it, or a class of your own, to OperatorAssurance.
 */
final readonly class SessionConfirmationAssurance implements OperatorAssurance
{
    public const string SESSION_KEY = 'auth.password_confirmed_at';

    public function __construct(private Container $container) {}

    public function hasSecondFactor(Authenticatable $operator): bool
    {
        return false;
    }

    public function recentlyConfirmedAt(Authenticatable $operator): ?DateTimeInterface
    {
        // The request is resolved per call: this binding may outlive one request.
        $request = $this->container->bound('request') ? $this->container->make('request') : null;
        if (! $request instanceof Request || ! $request->hasSession()) {
            return null;
        }

        $confirmedAt = $request->session()->get(self::SESSION_KEY);

        return is_numeric($confirmedAt) ? CarbonImmutable::createFromTimestamp((int) $confirmedAt) : null;
    }
}
