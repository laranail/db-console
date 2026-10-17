<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Simtabi\Laranail\DBConsole\Exceptions\GrantNotApplied;

it('names each privilege the grant did not give, in the user-safe message', function (): void {
    $e = GrantNotApplied::missing('shop_prod', 'app', [
        ['object' => 'schema public', 'privilege' => 'CREATE'],
        ['object' => 'table orders', 'privilege' => 'SELECT'],
    ]);

    expect($e->code())->toBe(ExceptionCode::GrantNotApplied)
        ->and($e->code()->httpStatus())->toBe(502)
        ->and($e->userMessage())->toContain("'app'")
        ->and($e->userMessage())->toContain("'shop_prod'")
        ->and($e->userMessage())->toContain('CREATE on schema public, SELECT on table orders')
        ->and($e->context()['missing'])->toHaveCount(2);
});
