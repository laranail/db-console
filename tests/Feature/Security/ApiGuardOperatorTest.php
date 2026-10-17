<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\Operator;
use Simtabi\Laranail\DBConsole\Http\Middleware\ApiGuard;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.api.enabled', true);
});

it('makes the API guard the default for the request, so the Authorizer sees the caller', function (): void {
    config()->set('laranail.db-console.api.guard', 'sanctum');
    $operator = OperatorSecurity::enrolled();

    $request = Request::create('/api/db-console/servers', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
    $request->setUserResolver(fn (): Operator => $operator);

    $response = app(ApiGuard::class)->handle($request, fn (): Response => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(200)
        ->and(auth()->getDefaultDriver())->toBe('sanctum');
});
