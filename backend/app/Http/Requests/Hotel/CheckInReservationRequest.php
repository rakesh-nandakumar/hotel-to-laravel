<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class CheckInReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_reservations.check_in') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_number' => ['nullable', 'string', 'max:50'],
            'apply_early_surcharge' => ['nullable', 'boolean'],
            'item_checks' => ['nullable', 'array'],
            'item_checks.*.room_id' => ['required', 'integer', 'exists:rooms,id'],
            'item_checks.*.items' => ['required', 'array'],
            'item_checks.*.items.*.item' => ['required', 'string', 'max:150'],
            'item_checks.*.items.*.ok' => ['required', 'boolean'],
            'item_checks.*.items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
