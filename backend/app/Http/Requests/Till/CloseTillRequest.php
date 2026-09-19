<?php

namespace App\Http\Requests\Till;

use Illuminate\Foundation\Http\FormRequest;

class CloseTillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('till.close') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional: omitting it accepts the session's own expected balance
            // as the counted amount, which closes at zero variance. Only a
            // physical recount that disagrees needs to be sent.
            'closing_cash' => ['nullable', 'integer', 'min:0'],
            // Required only when closing_cash differs from the session's
            // expected balance — enforced in TillService::closeTill(), which
            // is the only place that knows that comparison value.
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
