<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Models\AuditLog;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => $this->faker->randomElement(OperationType::cases()),
            'target' => 'db_' . $this->faker->lexify('?????'),
            'server' => 'srv_' . $this->faker->lexify('????'),
            'engine' => EngineType::Mysql,
            'outcome' => OperationOutcome::Succeeded,
            'sanitized_message' => null,
            'ip' => $this->faker->ipv4(),
        ];
    }

    public function failed(): self
    {
        return $this->state(['outcome' => OperationOutcome::Failed]);
    }
}
