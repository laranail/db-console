<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Carbon\CarbonImmutable;

/**
 * A break-glass bypass issued from the host's shell by
 * `laranail::db-console.security.recover`: db-console's assurance requirements
 * are lifted for the operator until it expires.
 *
 * @property string $assignee_type
 * @property string $assignee_id
 * @property ?string $issued_by
 * @property CarbonImmutable $expires_at
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
            'expires_at' => 'immutable_datetime',
        ];
    }
}
