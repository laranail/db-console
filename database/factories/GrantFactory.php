<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Database\Factories;

use Simtabi\Laranail\DBConsole\Models\Grant;
use Simtabi\Laranail\DBConsole\Enums\GrantScope;
use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;

/**
 * @extends Factory<Grant>
 *
 * Presets and privileges come from the real enums, so generated grants are
 * valid by construction.
 */
final class GrantFactory extends Factory
{
    protected $model = Grant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $preset = $this->faker->randomElement([
            PrivilegePreset::ReadOnly,
            PrivilegePreset::ReadWrite,
            PrivilegePreset::AppStandard,
        ]);

        return [
            'account_id'  => DbAccount::factory(),
            'database_id' => ManagedDatabase::factory(),
            'preset'      => $preset,
            'privileges'  => $this->privilegesFor($preset),
            'scope'       => GrantScope::Database,
        ];
    }

    /**
     * Set the preset AND its matching resolved privilege list together, so a
     * grant is always internally consistent.
     */
    public function preset(PrivilegePreset $preset): self
    {
        return $this->state([
            'preset'     => $preset,
            'privileges' => $this->privilegesFor($preset),
        ]);
    }

    /**
     * @return list<string>
     */
    private function privilegesFor(PrivilegePreset $preset): array
    {
        return array_map(
            static fn ($p): string => $p->value,
            $preset->privileges(),
        );
    }
}
