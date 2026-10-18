<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Override;
use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Http\Api\ApiSettings;
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
            'host'     => ['required', 'string', new HostRule],
            'confirm'  => ['required', 'string', 'same:username'],
        ];
    }

    /**
     * @return array<string, string>
     */
    /**
     * Over HTTP the username is the route's `{username}`. The host is
     * required: the API used to default it to `%`, which dropped whichever
     * `user@%` existed when the caller meant another host. With
     * api.confirm_destructive off the confirmation is taken as given; the
     * rules are unchanged for the web UI, which always asks for it.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $username = $this->route('username');
        if (is_string($username)) {
            $this->merge(['username' => $username]);
        }

        if (! $this->has('confirm') && ! $this->container->make(ApiSettings::class)->confirmsDestructive()) {
            $this->merge(['confirm' => $this->input('username')]);
        }
    }

    #[Override]
    public function messages(): array
    {
        return [
            'confirm.same' => (string) __('laranail-db-console::validation.confirm_username'),
        ];
    }
}
