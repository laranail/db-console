<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use ReflectionProperty;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;

/**
 * Builders for the operator-assurance tests: an operators table, operators in
 * each assurance state, Owner assignments, and the host's assurance binding.
 */
final class OperatorSecurity
{
    public static function setUp(bool $bindHostAssurance = true): void
    {
        Schema::create('operators', function ($table): void {
            $table->id();
            $table->string('name');
            $table->boolean('second_factor')->default(false);
            $table->timestamp('confirmed_at')->nullable();
        });

        config()->set('laranail.db-console.rbac.user_model', Operator::class);
        app(RbacDriver::class)->seedDefaultRoles();

        if ($bindHostAssurance) {
            app()->instance(OperatorAssurance::class, new FakeOperatorAssurance);
        }
    }

    public static function enrolled(string $name = 'enrolled'): Operator
    {
        return Operator::query()->create(['name' => $name, 'second_factor' => true]);
    }

    public static function unenrolled(string $name = 'unenrolled'): Operator
    {
        return Operator::query()->create(['name' => $name]);
    }

    public static function confirm(Operator $operator, int $secondsAgo = 0): Operator
    {
        $operator->forceFill(['confirmed_at' => now()->subSeconds($secondsAgo)])->save();

        return $operator;
    }

    public static function owner(Operator $operator): Operator
    {
        app(RbacDriver::class)->assign($operator, ConsoleRole::Owner->value, 'global');

        return $operator;
    }

    /**
     * Pretend the current process is serving an HTTP request, not a shell.
     */
    public static function overHttp(): void
    {
        $property = new ReflectionProperty(app(), 'isRunningInConsole');
        $property->setValue(app(), false);
    }
}
