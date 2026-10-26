<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVenueBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_venue_bookings.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'venue_id' => ['sometimes', 'nullable', 'integer', 'exists:venues,id'],
            'venue_ids' => ['sometimes', 'nullable', 'array'],
            'venue_ids.*' => ['integer', 'exists:venues,id'],
            'client_name' => ['sometimes', 'string', 'max:150'],
            'client_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'client_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'guest_id' => ['sometimes', 'nullable', 'integer', 'exists:guests,id'],
            'event_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'date' => ['sometimes', 'date'],
            'hours' => ['sometimes', 'nullable', 'numeric', 'min:0.5'],
            'guest_count' => ['sometimes', 'integer', 'min:0'],
            'seating' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'av_needs' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'decoration' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'catering_by_hotel' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Package pricing fields
            'package_type' => ['sometimes', 'nullable', 'string', 'in:hall_only,hall_food'],
            'per_plate_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'hall_charge_used' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'service_charge_pct' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'service_charge_on' => ['sometimes', 'nullable', 'string', 'in:hall,food,both'],
            'byod_selected' => ['sometimes', 'nullable', 'boolean'],
            'dj_required' => ['sometimes', 'nullable', 'boolean'],
            'venue_extras' => ['sometimes', 'nullable', 'array'],
            'venue_extras.*.description' => ['required', 'string', 'max:255'],
            'venue_extras.*.amount' => ['required', 'integer', 'min:0'],
            'venue_extras.*.charge_type' => ['nullable', 'string'],
            'venue_extras.*.is_percentage' => ['nullable', 'boolean'],
        ];
    }
}
