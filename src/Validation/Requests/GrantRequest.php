<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Validation\Rules\HostRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\IdentifierRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\PrivilegeRule;
use Simtabi\Laranail\DBConsole\Validation\Rules\UsernameRule;
use Simtabi\Laranail\Enumerator\Rules\EnumValue;

/**
 * Granting a preset (or custom privilege list) to an account on a
 * database. Forbidden privileges are rejected here with the same message
 * the domain guard raises.
 */
final class GrantRequest extends FormRequest
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
            'preset' => ['required', 'string', EnumValue::for(PrivilegePreset::class)],
            'privileges' => ['required_if:preset,custom', 'array', 'min:1'],
            'privileges.*' => ['string', new PrivilegeRule],
        ];
    }
}
