<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Security\StepUpProof;
use Simtabi\Laranail\DBConsole\Security\RecoveryCodes;
use Simtabi\Laranail\DBConsole\Exceptions\StepUpRequired;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.security.step_up.driver', 'totp');
});

it('regenerates recovery codes only behind step-up', function (): void {
    $operator = OperatorSecurity::enrolled();
    $this->actingAs($operator);
    OperatorSecurity::fakeAuthKit();

    expect(fn () => app(RecoveryCodes::class)->regenerate($operator))->toThrow(StepUpRequired::class);

    app(StepUpProof::class)->present('123456');
    expect(app(RecoveryCodes::class)->regenerate($operator))->toBe(['AAAA-BBBB', 'CCCC-DDDD']);
});
