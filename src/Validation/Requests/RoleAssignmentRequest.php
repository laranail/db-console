<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Validation\Rules\ScopeRule;

/**
 * Assigning a console role to an operator at a scope. The role may be a
 * shipped or custom role; existence is checked by the access layer.
 */
final class RoleAssignmentRequest extends FormRequest
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
            'user_id' => ['required'],
            'role'    => ['required', 'string', 'max:64'],
            'scope'   => ['required', 'string', new ScopeRule],
        ];
    }
}
