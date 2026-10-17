<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Simtabi\Laranail\DBConsole\Security\StepUpProof;
use Simtabi\Laranail\DBConsole\Http\Middleware\ApiGuard;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.api.enabled', true);
    config()->set('laranail.db-console.security.two_factor.enforce', 'require');
});

function callApi(string $method, object $operator, array $headers = []): Response
{
    $request = Request::create('/api/db-console/servers', $method, server: ['REMOTE_ADDR' => '127.0.0.1']);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }
    $request->setUserResolver(fn (): object => $operator);

    return app(ApiGuard::class)->handle($request, fn (): Response => new JsonResponse(['ok' => true]));
}

it('refuses a write from an operator without two-factor, with a machine-readable code', function (): void {
    $response = callApi('POST', OperatorSecurity::unenrolled());

    expect($response->getStatusCode())->toBe(403)
        ->and(json_decode((string) $response->getContent(), true)['code'])->toBe('two_factor.required');
});

it('lets the same operator read during the grace window', function (): void {
    expect(callApi('GET', OperatorSecurity::unenrolled())->getStatusCode())->toBe(200);
});

it('lets an enrolled operator write', function (): void {
    expect(callApi('POST', OperatorSecurity::enrolled())->getStatusCode())->toBe(200);
});

it('hands the X-DB-Console-OTP header to step-up', function (): void {
    callApi('DELETE', OperatorSecurity::enrolled(), ['X-DB-Console-OTP' => ' 123456 ']);

    expect(app(StepUpProof::class)->otp())->toBe('123456');
});

it('makes the API guard the default for the request, so the gate sees the caller', function (): void {
    config()->set('laranail.db-console.api.guard', 'sanctum');

    callApi('GET', OperatorSecurity::enrolled());

    expect(auth()->getDefaultDriver())->toBe('sanctum');
});
