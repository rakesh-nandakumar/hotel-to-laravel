<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFolioPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_folios.payment') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::PAYMENT_METHOD)],
            'amount' => ['required', 'integer', 'min:1'],
            'kind' => ['nullable', 'string', Rule::in(['payment', 'deposit'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
