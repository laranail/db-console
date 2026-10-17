<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpFoundation\Response;
use Simtabi\Laranail\DBConsole\Security\StepUpProof;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Security\TwoFactorEnforcer;
use Simtabi\Laranail\DBConsole\Exceptions\TwoFactorRequired;

/**
 * The single front door to the REST API (section 22). Off by default; when on
 * it enforces, in order: the API is enabled, the request is over HTTPS
 * (outside local), the caller is authenticated via the configured guard
 * (sanctum | passport), the caller's IP is allow-listed, and per-token rate
 * limits. Authorization itself still happens in the services (same Gate as
 * the CLI/UI) — this middleware only gates transport + identity.
 *
 * Identity includes two-factor: an operator two-factor enforcement refuses is
 * stopped here, before any service runs (safe methods count as reads for the
 * enrolment grace window), and the `X-DB-Console-OTP` header is handed to
 * step-up for the actions that need it.
 */
final readonly class ApiGuard
{
    public const string OTP_HEADER = 'X-DB-Console-OTP';

    public function __construct(
        private Config $config,
        private AuthFactory $auth,
        private TwoFactorEnforcer $twoFactor,
        private StepUpProof $proof,
    ) {}

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
        $user = $request->user($guard);
        if (! $user instanceof Authenticatable) {
            return $this->deny('Unauthenticated.', 401);
        }

        // Bind the resolved user so the services' Gate sees the API caller.
        // The gate and the two-factor check resolve the operator through the
        // default guard, so make the API guard the default for this request.
        $request->setUserResolver(fn () => $request->user($guard));
        $this->auth->shouldUse($guard);

        try {
            $this->twoFactor->enforce($user, 'api', $request->isMethodSafe());
        } catch (TwoFactorRequired $e) {
            return new JsonResponse(['message' => $e->userMessage(), 'code' => $e->code()->value], $e->code()->httpStatus());
        }

        $otp = $request->header(self::OTP_HEADER);
        $this->proof->present(is_string($otp) ? $otp : null);

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
