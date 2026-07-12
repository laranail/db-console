<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Models\DbServer;

/**
 * @extends Factory<DbServer>
 */
final class DbServerFactory extends Factory
{
    protected $model = DbServer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'srv_' . $this->faker->unique()->lexify('????');

        return [
            'name' => $name,
            'engine' => $this->faker->randomElement(EngineType::cases()),
            'host' => $this->faker->ipv4(),
            'port' => 3306,
            'label' => $this->faker->words(2, true),
            'connection_ref' => 'db_console_admin',
            'is_managed' => true,
            'version' => 1,
        ];
    }
}
