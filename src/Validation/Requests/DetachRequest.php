<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\IdentifierRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;

/**
 * Batch detach: revoke users from databases. Never drops the user or the
 * database — only the grants.
 */
final class DetachRequest extends FormRequest
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
            'users' => ['required', 'array', 'min:1'],
            'users.*.username' => ['required', 'string', new UsernameRule],
            'users.*.host' => ['required', 'string', new HostRule],
            'databases' => ['required', 'array', 'min:1'],
            'databases.*' => ['required', 'string', new IdentifierRule],
        ];
    }
}
