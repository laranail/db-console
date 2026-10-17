<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Stands in for laranail/authkit's TwoFactorAuthentication, bound under its
 * class name, so the TOTP path is exercised without installing authkit.
 * Counts verify() calls so a test can see a proof was checked only once.
 */
final class FakeAuthKit
{
    public int $verifications = 0;

    public function __construct(private readonly string $validCode = '123456') {}

    public function verify(Authenticatable $user, string $code): bool
    {
        $this->verifications++;

        return $code === $this->validCode;
    }

    /**
     * @return list<string>
     */
    public function rotateRecoveryCodes(): array
    {
        return ['AAAA-BBBB', 'CCCC-DDDD'];
    }
}
