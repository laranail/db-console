<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use ReflectionProperty;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Security\StepUp\TotpStepUpVerifier;

/**
 * Builders for the operator-security tests: an operators table with the
 * two-factor columns, operators in each enrolment state, and Owner assignments.
 */
final class OperatorSecurity
{
    public static function setUp(): void
    {
        Schema::create('operators', function ($table): void {
            $table->id();
            $table->string('name');
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
        });

        config()->set('laranail.db-console.rbac.user_model', Operator::class);
        app(RbacDriver::class)->seedDefaultRoles();
    }

    public static function enrolled(string $name = 'enrolled'): Operator
    {
        return Operator::query()->create([
            'name'                      => $name,
            'two_factor_confirmed_at'   => now(),
            'two_factor_recovery_codes' => 'encrypted-codes',
        ]);
    }

    public static function unenrolled(string $name = 'unenrolled'): Operator
    {
        return Operator::query()->create(['name' => $name]);
    }

    public static function confirmedWithoutCodes(string $name = 'no-codes'): Operator
    {
        return Operator::query()->create(['name' => $name, 'two_factor_confirmed_at' => now()]);
    }

    public static function owner(Operator $operator): Operator
    {
        app(RbacDriver::class)->assign($operator, ConsoleRole::Owner->value, 'global');

        return $operator;
    }

    public static function fakeAuthKit(string $validCode = '123456'): FakeAuthKit
    {
        $fake = new FakeAuthKit($validCode);
        app()->instance(TotpStepUpVerifier::AUTHKIT, $fake);

        return $fake;
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
