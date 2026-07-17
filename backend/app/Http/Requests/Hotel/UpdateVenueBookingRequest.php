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
            'client_name' => ['sometimes', 'string', 'max:150'],
            'client_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'client_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'guest_id' => ['sometimes', 'nullable', 'integer', 'exists:guests,id'],
            'event_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'date' => ['sometimes', 'date'],
            'start_time' => ['sometimes', 'nullable', 'string', 'max:10'],
            'end_time' => ['sometimes', 'nullable', 'string', 'max:10'],
            'hours' => ['sometimes', 'nullable', 'numeric', 'min:0.5'],
            'guest_count' => ['sometimes', 'integer', 'min:0'],
            'seating' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'av_needs' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'decoration' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'catering_by_hotel' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
