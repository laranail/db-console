<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;

/**
 * @extends Factory<ManagedDatabase>
 */
final class ManagedDatabaseFactory extends Factory
{
    protected $model = ManagedDatabase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Names pass the DbName allow-list (letters, digits, underscore).
        return [
            'server_name' => 'srv_' . $this->faker->lexify('????'),
            'name'        => 'db_' . $this->faker->unique()->lexify('??????'),
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'is_managed'  => true,
            'version'     => 1,
        ];
    }

    public function unmanaged(): self
    {
        return $this->state(['is_managed' => false]);
    }
}
