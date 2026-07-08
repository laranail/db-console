<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single front door to the REST API (section 22). Off by default; when on
 * it enforces, in order: the API is enabled, the request is over HTTPS
 * (outside local), the caller is authenticated via the configured guard
 * (sanctum | passport), the caller's IP is allow-listed, and per-token rate
 * limits. Authorization itself still happens in the services (same Gate as
 * the CLI/UI) — this middleware only gates transport + identity.
 */
final readonly class ApiGuard
{
    public function __construct(private Config $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->config->get('laranail.db-console.api.enabled', false)) {
            return $this->deny('The DBConsole API is disabled.', 404);
        }

        if (! $request->isSecure() && ! $this->isLocal($request)) {
            return $this->deny('The DBConsole API requires HTTPS.', 426);
        }

        if (! $this->ipAllowed($request)) {
            return $this->deny('Your IP is not permitted to call the DBConsole API.', 403);
        }

        $guard = (string) $this->config->get('laranail.db-console.api.guard', 'sanctum');
        if ($request->user($guard) === null) {
            return $this->deny('Unauthenticated.', 401);
        }

        // Bind the resolved user so the services' Gate sees the API caller.
        $request->setUserResolver(fn () => $request->user($guard));

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
