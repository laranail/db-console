<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Override;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;

/**
 * Dropping an account requires typed confirmation: the confirm field must
 * repeat the username exactly.
 */
final class DropAccountRequest extends FormRequest
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
            'confirm' => ['required', 'string', 'same:username'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'confirm.same' => (string) __('db-console::validation.confirm_username'),
        ];
    }
}
