<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Override;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\DBConsole\Enums\Assurance;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * Refused because the operator holds the permission but not the assurance the
 * operation requires (security.assurance), as the host reported it through
 * OperatorAssurance. The message says what to do; `missing()` (and the API
 * body's `missing` list) says which, so the host can send the operator to
 * enrol in a second factor or to confirm who they are.
 */
final class AssuranceRequired extends AuthorizationException
{
    /** @var list<Assurance> */
    private array $missing = [];

    /**
     * @param list<Assurance> $missing
     */
    public static function for(string $ability, array $missing): self
    {
        $values = array_map(static fn (Assurance $a): string => $a->value, $missing);

        $exception = new self(
            message: "'{$ability}' requires " . implode(' and ', $values),
            userParams: ['ability' => $ability, 'action' => self::action($missing)],
            context: ['ability' => $ability, 'missing' => $values],
        );
        $exception->missing = $missing;

        return $exception;
    }

    /**
     * @return list<Assurance>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::AssuranceRequired;
    }

    #[Override]
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'message' => $this->userMessage(),
            'code'    => $this->code()->value,
            'missing' => array_map(static fn (Assurance $a): string => $a->value, $this->missing),
        ], $this->code()->httpStatus());
    }

    /**
     * @param list<Assurance> $missing
     */
    private static function action(array $missing): string
    {
        $steps = [];
        if (in_array(Assurance::SecondFactor, $missing, true)) {
            $steps[] = (string) __('laranail-db-console::exceptions.assurance.enrol');
        }
        if (in_array(Assurance::RecentConfirmation, $missing, true)) {
            $steps[] = (string) __('laranail-db-console::exceptions.assurance.confirm');
        }

        return implode(' ', $steps);
    }
}
