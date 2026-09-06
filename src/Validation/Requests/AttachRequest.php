<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Enumerator\Rules\EnumValue;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\PrivilegeRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\IdentifierRule;

/**
 * Batch attach: several users to several databases with one preset. Every
 * (user, database) pairing becomes its own audited grant.
 */
final class AttachRequest extends FormRequest
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
            'users'            => ['required', 'array', 'min:1'],
            'users.*.username' => ['required', 'string', new UsernameRule],
            'users.*.host'     => ['required', 'string', new HostRule],
            'databases'        => ['required', 'array', 'min:1'],
            'databases.*'      => ['required', 'string', new IdentifierRule],
            'preset'           => ['required', 'string', EnumValue::for(PrivilegePreset::class)],
            'privileges'       => ['required_if:preset,custom', 'array', 'min:1'],
            'privileges.*'     => ['string', new PrivilegeRule],
        ];
    }
}
