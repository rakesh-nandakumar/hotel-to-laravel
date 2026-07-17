<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_reservations.cancel') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'refund_method' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::PAYMENT_METHOD)],
        ];
    }
}
