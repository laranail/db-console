<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\DBConsole\Enums\Charset;
use Simtabi\Laranail\DBConsole\Enums\Collation;
use Simtabi\Laranail\Enumerator\Rules\EnumValue;
use Simtabi\Laranail\DBConsole\Validation\Rules\IdentifierRule;

/**
 * The single validation definition for creating a database — used directly
 * by the REST API, surfaced to the CLI through Prompter validators, and
 * imported by the web UI via RuleProvider. Authorization is enforced by
 * the service layer's scoped gate, not here.
 */
final class CreateDatabaseRequest extends FormRequest
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
            'name'      => ['required', 'string', new IdentifierRule],
            'charset'   => ['nullable', 'string', EnumValue::for(Charset::class)],
            'collation' => ['nullable', 'string', EnumValue::for(Collation::class)],
        ];
    }
}
