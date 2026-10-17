# Implement OperatorAssurance

Tell db-console what your authentication knows about an operator, with Laravel Fortify or laranail/authkit.

## Steps

Write a class in your application that answers from your own authentication. Both Fortify and laranail/authkit record a confirmed second factor in `two_factor_confirmed_at`, and Laravel's `password.confirm` flow (which authkit-preset also satisfies with a passkey) writes `auth.password_confirmed_at` to the session:

```php
namespace App\Security;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;

final class AppOperatorAssurance implements OperatorAssurance
{
    public function hasSecondFactor(Authenticatable $operator): bool
    {
        return $operator->two_factor_confirmed_at !== null;
    }

    public function recentlyConfirmedAt(Authenticatable $operator): ?DateTimeInterface
    {
        $at = request()->hasSession() ? request()->session()->get('auth.password_confirmed_at') : null;

        return is_numeric($at) ? CarbonImmutable::createFromTimestamp((int) $at) : null;
    }
}
```

Bind it in a service provider's `register()`:

```php
$this->app->bind(
    \Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance::class,
    \App\Security\AppOperatorAssurance::class,
);
```

API callers carry no session. If your API confirms operators another way, a one-time code your own middleware verifies for example, return that time from `recentlyConfirmedAt()` instead. db-console only compares it with `security.step_up.ttl`.

Before disabling an operator's second factor, call `app(\Simtabi\Laranail\DBConsole\Security\OwnerSafety::class)->assertMayLoseSecondFactor($user)`, so the last global Owner with one cannot remove it.

Run `doctor`: `security:assurance-binding` names your class once it is bound. Start with `DB_CONSOLE_ASSURANCE_ENFORCE=report`, then `require`. See [Operator assurance](../tools/operator-assurance.md).

---

[← Docs index](../../README.md#documentation)
