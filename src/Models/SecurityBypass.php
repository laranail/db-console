<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Carbon\CarbonImmutable;

/**
 * A break-glass bypass issued from the host's shell by
 * `laranail::db-console.security.recover`: the operator is treated as enrolled
 * until it expires, and may satisfy step-up with it once.
 *
 * @property string $assignee_type
 * @property string $assignee_id
 * @property ?string $issued_by
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $step_up_used_at
 */
final class SecurityBypass extends CatalogModel
{
    protected string $baseTable = 'security_bypasses';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'expires_at'      => 'immutable_datetime',
            'step_up_used_at' => 'immutable_datetime',
        ];
    }
}
