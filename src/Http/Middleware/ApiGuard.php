<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpFoundation\Response;
use Simtabi\Laranail\DBConsole\Http\Api\ApiSettings;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The single front door to the REST API (section 22). Off by default; when on
 * it enforces, in order: the API is enabled, the request is over HTTPS
 * (api.require_https, outside loopback), the caller's IP is allow-listed,
 * and the caller is authenticated via the configured guard (api.auth:
 * sanctum | passport). The api.rate_limit limiter runs after it, keyed on
 * the authenticated caller. Authorization itself still happens in the services (same Gate as
 * the CLI/UI) — this middleware only gates transport + identity.
 */
final readonly class ApiGuard
{
    public function __construct(
        private Config $config,
        private ApiSettings $settings,
        private AuthFactory $auth,
        private RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The API answers in JSON whatever the client asked for. Exceptions
        // render their code and status only for a request that expects JSON,
        // so without this a client that sent no Accept header got a 500 for a
        // refusal and a redirect for a validation error.
        $request->headers->set('Accept', 'application/json');

        if (! (bool) $this->config->get('laranail.db-console.api.enabled', false)) {
            return $this->deny('The DBConsole API is disabled.', 404);
        }

        // api.require_https (default true). Loopback is always allowed, so a
        // local client and the test suite need no certificate.
        if ($this->settings->requiresHttps() && ! $request->isSecure() && ! $this->isLocal($request)) {
            return $this->deny('The DBConsole API requires HTTPS.', 426);
        }

        if (! $this->ipAllowed($request)) {
            return $this->deny('Your IP is not permitted to call the DBConsole API.', 403);
        }

        // api.auth_failure_limit: an IP that keeps failing to authenticate is
        // refused outright until its window ends, before its credentials are
        // even looked at.
        $failures = $this->settings->authFailureLimit();
        $failureKey = 'laranail-db-console:api-auth-failures:' . $request->ip();
        if ($failures !== null && $this->limiter->tooManyAttempts($failureKey, $failures[0])) {
            return $this->deny('Too many failed authentication attempts.', 429)
                ->header('Retry-After', (string) $this->limiter->availableIn($failureKey));
        }

        $guard = $this->settings->guard();
        $user = $request->user($guard);
        if (! $user instanceof Authenticatable) {
            if ($failures !== null) {
                $this->limiter->hit($failureKey, $failures[1] * 60);
            }

            return $this->deny('Unauthenticated.', 401);
        }

        // The Gate the services authorize through resolves its user from the
        // default guard, so make the API guard the default for this request,
        // as Laravel's own Authenticate middleware does. Without it a sanctum
        // caller reached the Gate as nobody and was denied everything.
        $this->auth->shouldUse($guard);

        // Bind the resolved user so the services' Gate sees the API caller.
        // The resolver returns the user it already has: one that asked the
        // request again would call itself, and recursed until the stack ran
        // out on every authenticated request before this was fixed.
        $request->setUserResolver(static fn (): mixed => $user);

        return $next($request);
    }

    private function ipAllowed(Request $request): bool
    {
        /** @var list<string> $allowed */
        $allowed = (array) $this->config->get('laranail.db-console.api.allowed_ips', []);
        if ($allowed === []) {
            return true;   // empty allow-list = no IP restriction
        }

        return in_array((string) $request->ip(), $allowed, true);
    }

    private function isLocal(Request $request): bool
    {
        return in_array((string) $request->ip(), ['127.0.0.1', '::1'], true);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
