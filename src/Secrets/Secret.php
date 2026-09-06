<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets;

use Stringable;
use JsonSerializable;
use SensitiveParameter;
use Simtabi\Laranail\DBConsole\Domain\Concerns\RedactsSelf;

/**
 * Arbitrary secret material (admin credentials, webhook signing secrets)
 * flowing through the SecretVault seam. Self-redacting everywhere; only
 * reveal() returns the real value.
 */
final class Secret implements JsonSerializable, Stringable
{
    use RedactsSelf;

    private const string REDACTED = '[redacted]';

    private string $value;

    public function __construct(#[SensitiveParameter] string $value)
    {
        $this->value = $value;
    }

    /**
     * Generate random secret material (URL-safe base64, no padding). The
     * entropy floor is 16 bytes regardless of the argument.
     */
    public static function generate(int $bytes = 32): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(max(16, $bytes))), '+/', '-_'), '='));
    }

    public function reveal(): string
    {
        return $this->value;
    }
}
