<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCorporateSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_corporate.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'method' => [
                'required',
                'string',
                Rule::exists('lookups', 'code')->where('type', LookupType::PAYMENT_METHOD),
                Rule::notIn([PaymentMethod::CORPORATE_CREDIT, PaymentMethod::LOYALTY_POINTS]),
            ],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.not_in' => 'Settlement must be a real payment method.',
        ];
    }
}
