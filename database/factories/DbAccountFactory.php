<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Database\Factories;

use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DbAccount>
 *
 * Never generates a password — accounts do not store one.
 */
final class DbAccountFactory extends Factory
{
    protected $model = DbAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_name' => 'srv_' . $this->faker->lexify('????'),
            'username'    => 'usr_' . $this->faker->unique()->lexify('?????'),
            'host'        => $this->faker->randomElement(['localhost', '%', '10.0.%']),
            'is_managed'  => true,
            'version'     => 1,
        ];
    }
}
