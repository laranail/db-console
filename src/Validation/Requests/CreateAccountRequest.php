<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\PasswordStrengthRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;

/**
 * Creating an account: provide a strong password or ask for a generated
 * one (returned exactly once). Authorization lives in the service gate.
 */
final class CreateAccountRequest extends FormRequest
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
            'host' => ['required', 'string', new HostRule],
            'password' => ['required_without:generate', 'nullable', 'string', new PasswordStrengthRule],
            'generate' => ['nullable', 'boolean'],
            'require_tls' => ['nullable', 'boolean'],
        ];
    }
}
