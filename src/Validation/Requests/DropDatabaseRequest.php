<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Override;
use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Validation\Rules\IdentifierRule;

/**
 * Dropping a database requires typed confirmation: the confirm field must
 * repeat the database name exactly. This is the API/UI equivalent of the
 * CLI's typed confirmation.
 */
final class DropDatabaseRequest extends FormRequest
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
            'name'    => ['required', 'string', new IdentifierRule],
            'confirm' => ['required', 'string', 'same:name'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'confirm.same' => (string) __('laranail-db-console::validation.confirm_name'),
        ];
    }
}
