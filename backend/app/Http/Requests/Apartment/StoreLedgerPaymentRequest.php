<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLedgerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_ledgers.payment') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in([
                PaymentMethod::CASH, PaymentMethod::CARD, PaymentMethod::LANKAQR, PaymentMethod::BANK_TRANSFER,
            ])],
            'amount' => ['required', 'integer', 'min:1'],
            'kind' => ['nullable', 'string', Rule::in(['payment', 'deposit'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
