<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_reservations.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'guest_id' => ['required_without:new_guest', 'nullable', 'integer', 'exists:guests,id'],
            'new_guest' => ['required_without:guest_id', 'nullable', 'array'],
            'new_guest.name' => ['required_with:new_guest', 'string', 'max:150'],
            'new_guest.phone' => ['nullable', 'string', 'max:30'],
            'new_guest.email' => ['nullable', 'string', 'email', 'max:255'],
            'new_guest.id_number' => ['nullable', 'string', 'max:50'],
            'new_guest.nationality' => ['nullable', 'string', 'max:100'],

            'channel' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::BOOKING_CHANNEL)],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1'],
            'children' => ['nullable', 'integer', 'min:0'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'corporate_account_id' => ['nullable', 'integer', 'exists:corporate_accounts,id'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.room_id' => ['required', 'integer', 'exists:rooms,id'],
            'rooms.*.nightly_rate' => ['nullable', 'integer', 'min:0'],

            'group' => ['nullable', 'array'],
            'group.name' => ['required_with:group', 'string', 'max:150'],
            'group.contact_name' => ['nullable', 'string', 'max:150'],
            'group.contact_phone' => ['nullable', 'string', 'max:30'],

            'deposit_payment' => ['nullable', 'array'],
            'deposit_payment.method' => [
                'required_with:deposit_payment', 'string',
                Rule::exists('lookups', 'code')->where('type', LookupType::PAYMENT_METHOD),
            ],
            'deposit_payment.amount' => ['required_with:deposit_payment', 'integer', 'min:1'],
            'deposit_payment.reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
