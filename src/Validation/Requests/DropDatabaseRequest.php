<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Override;
use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Http\Api\ApiSettings;
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

    #[Override]
    public function messages(): array
    {
        return [
            'confirm.same' => (string) __('laranail-db-console::validation.confirm_name'),
        ];
    }

    /**
     * @return array<string, string>
     */
    /**
     * Over HTTP the name is the route's `{name}`, so the body need not repeat
     * it. With api.confirm_destructive off the API takes the confirmation as
     * given; the rules themselves are unchanged, because the web UI applies
     * them through RuleProvider and always asks for the typed name.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $name = $this->route('name');
        if (is_string($name)) {
            $this->merge(['name' => $name]);
        }

        if (! $this->has('confirm') && ! $this->container->make(ApiSettings::class)->confirmsDestructive()) {
            $this->merge(['confirm' => $this->input('name')]);
        }
    }
}
