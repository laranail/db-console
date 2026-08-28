<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Database\Factories;

use Simtabi\Laranail\DBConsole\Models\DbServer;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'name'           => $name,
            'engine'         => $this->faker->randomElement(EngineType::cases()),
            'host'           => $this->faker->ipv4(),
            'port'           => 3306,
            'label'          => $this->faker->words(2, true),
            'connection_ref' => 'db_console_admin',
            'is_managed'     => true,
            'version'        => 1,
        ];
    }
}
