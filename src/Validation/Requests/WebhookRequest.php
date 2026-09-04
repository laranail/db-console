<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Enumerator\Rules\EnumValue;
use Simtabi\Laranail\DBConsole\Enums\WebhookEvent;
use Simtabi\Laranail\DBConsole\Validation\Rules\ServerNameRule;

/**
 * Creating or updating a webhook subscription. The signing secret is
 * generated server-side and stored through the SecretVault — it is never
 * accepted as input.
 */
final class WebhookRequest extends FormRequest
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
            'url'      => ['required', 'url:https,http', 'max:2048'],
            'events'   => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', EnumValue::for(WebhookEvent::class)],
            'server'   => ['nullable', 'string', new ServerNameRule],
            'active'   => ['nullable', 'boolean'],
        ];
    }
}
