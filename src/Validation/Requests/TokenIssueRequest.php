<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Enumerator\Rules\EnumValue;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;

/**
 * Minting an API token. Abilities are ConsolePermission values; the token
 * layer additionally caps them at the holder's role, so a token can never
 * exceed the RBAC assignment behind it.
 */
final class TokenIssueRequest extends FormRequest
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
            'user_id'     => ['required'],
            'name'        => ['required', 'string', 'max:255'],
            'abilities'   => ['nullable', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', EnumValue::for(ConsolePermission::class)],
            'expires_at'  => ['nullable', 'date', 'after:now'],
        ];
    }
}
