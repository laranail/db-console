<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Throwable;

/**
 * Base of every DBConsole exception. Carries a stable machine-readable code
 * (a closed enum), a user-safe translated message separate from the
 * technical detail, and structured context for logs.
 *
 * Secrets never enter an exception: the Password/Secret value objects
 * redact themselves on interpolation, and constructors only place
 * known-safe values into $userParams and $context.
 */
abstract class DBConsoleException extends RuntimeException
{
    /**
     * @param  array<string, string|int|float>  $userParams  safe placeholders for the translated user message
     * @param  array<string, mixed>  $context  structured log detail; never contains secrets
     */
    public function __construct(
        string $message,
        private readonly array $userParams = [],
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The stable machine-readable code for this exception type.
     */
    abstract public function code(): ExceptionCode;

    /**
     * Sanitized, translated message safe to show in the UI, CLI, and API.
     */
    public function userMessage(): string
    {
        return (string) __('laranail-db-console::exceptions.' . $this->code()->value, $this->userParams);
    }

    /**
     * Structured detail for the log channel (secrets already stripped by
     * construction).
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Render as a secret-free JSON error for the REST API, mapping the
     * exception code to a meaningful HTTP status (never a raw 500). Only
     * applies to JSON/API requests; CLI and web fall through to the normal
     * handler. The body is the translated userMessage — no SQL, no secrets.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse(
            ['message' => $this->userMessage(), 'code' => $this->code()->value],
            $this->code()->httpStatus(),
        );
    }
}
