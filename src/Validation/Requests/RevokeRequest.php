<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\IdentifierRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\PrivilegeRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;

/**
 * Revoking privileges from an account on a database. Omitting privileges
 * revokes everything the account holds on that database.
 */
final class RevokeRequest extends FormRequest
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
            'database' => ['required', 'string', new IdentifierRule],
            'privileges' => ['nullable', 'array', 'min:1'],
            'privileges.*' => ['string', new PrivilegeRule],
        ];
    }
}
