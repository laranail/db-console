<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\PasswordStrengthRule;

/**
 * Rotating an account password: supply a strong one or ask for a generated
 * one (shown exactly once).
 */
final class RotatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', new UsernameRule],
            'host'     => ['required', 'string', new HostRule],
            'password' => ['required_without:generate', 'nullable', 'string', new PasswordStrengthRule],
            'generate' => ['nullable', 'boolean'],
        ];
    }
}
