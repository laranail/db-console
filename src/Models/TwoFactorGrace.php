<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Carbon\CarbonImmutable;

/**
 * When an operator without two-factor was first refused: the start of their
 * enrolment grace window, and when they and the security recipients were
 * last told about it. In `report` mode only `reported_at` is set, so a dry run
 * never starts the clock.
 *
 * @property string $assignee_type
 * @property string $assignee_id
 * @property ?CarbonImmutable $first_refused_at
 * @property ?CarbonImmutable $notified_at
 * @property ?CarbonImmutable $reported_at
 */
final class TwoFactorGrace extends CatalogModel
{
    protected string $baseTable = 'two_factor_grace';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'first_refused_at' => 'immutable_datetime',
            'notified_at'      => 'immutable_datetime',
            'reported_at'      => 'immutable_datetime',
        ];
    }
}
