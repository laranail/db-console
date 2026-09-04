<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation;

use InvalidArgumentException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The single source of field→rules per operation. Every caller — the REST
 * API (FormRequests directly), the CLI (Prompter validators), and the web
 * UI (live inline validation) — resolves the identical rule set from here,
 * so a rule change updates all three surfaces at once and nothing
 * re-declares validation.
 */
final class RuleProvider
{
    /**
     * The field→rules array for an operation, identified by its
     * FormRequest class.
     *
     * @param class-string<FormRequest> $requestClass
     *
     * @return array<string, list<mixed>>
     */
    public static function for(string $requestClass): array
    {
        if (! is_subclass_of($requestClass, FormRequest::class)) {
            throw new InvalidArgumentException(
                "{$requestClass} is not a FormRequest; RuleProvider only serves the shared validation layer",
            );
        }

        /** @var FormRequest $request */
        $request = new $requestClass;

        // rules() is a Laravel convention, not a base-class method — the
        // framework itself resolves it via method_exists.
        if (! method_exists($request, 'rules')) {
            throw new InvalidArgumentException("{$requestClass} does not define rules()");
        }

        $rules = $request->rules();
        if (! is_array($rules)) {
            throw new InvalidArgumentException("{$requestClass}::rules() must return an array");
        }

        /** @var array<string, list<mixed>> $rules */
        return $rules;
    }

    /**
     * The rules for one field of an operation — what a Prompter validator
     * or a Livewire property binds to.
     *
     * @param class-string<FormRequest> $requestClass
     *
     * @return list<mixed>
     */
    public static function field(string $requestClass, string $field): array
    {
        $rules = self::for($requestClass);

        if (! array_key_exists($field, $rules)) {
            throw new InvalidArgumentException("{$requestClass} has no field '{$field}'");
        }

        return $rules[$field];
    }

    /**
     * The custom validation messages for an operation, so callers building
     * their own Validator (CLI prompts, Livewire) show the same wording the
     * FormRequest would.
     *
     * @param class-string<FormRequest> $requestClass
     *
     * @return array<string, string>
     */
    public static function messages(string $requestClass): array
    {
        if (! is_subclass_of($requestClass, FormRequest::class)) {
            throw new InvalidArgumentException(
                "{$requestClass} is not a FormRequest; RuleProvider only serves the shared validation layer",
            );
        }

        /** @var FormRequest $request */
        $request = new $requestClass;

        /** @var array<string, string> */
        return $request->messages();
    }
}
