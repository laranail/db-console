<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;

/**
 * Editing account configuration. A host change runs the grant-preserving
 * recreate; --rotate optionally issues a fresh password during the move.
 */
final class EditAccountRequest extends FormRequest
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
            'new_host' => ['required', 'string', 'different:host', new HostRule],
            'rotate'   => ['nullable', 'boolean'],
        ];
    }
}
