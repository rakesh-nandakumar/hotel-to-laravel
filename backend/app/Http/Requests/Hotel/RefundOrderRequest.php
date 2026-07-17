<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_orders.refund') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::PAYMENT_METHOD)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
