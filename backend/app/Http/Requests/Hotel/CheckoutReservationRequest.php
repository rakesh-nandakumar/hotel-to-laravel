<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_reservations.checkout') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paymentMethod = Rule::exists('lookups', 'code')->where('type', LookupType::PAYMENT_METHOD);

        return [
            'apply_late_surcharge' => ['nullable', 'boolean'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required', 'string', $paymentMethod],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'refund_method' => ['nullable', 'string', $paymentMethod],
            'item_checks' => ['nullable', 'array'],
            'item_checks.*.room_id' => ['required', 'integer', 'exists:rooms,id'],
            'item_checks.*.items' => ['required', 'array'],
            'item_checks.*.items.*.item' => ['required', 'string', 'max:150'],
            'item_checks.*.items.*.ok' => ['required', 'boolean'],
            'item_checks.*.items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
