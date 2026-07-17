<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVenueBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_venue_bookings.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'client_name' => ['required', 'string', 'max:150'],
            'client_phone' => ['nullable', 'string', 'max:30'],
            'client_email' => ['nullable', 'string', 'email', 'max:255'],
            'guest_id' => ['nullable', 'integer', 'exists:guests,id'],
            'event_type' => ['nullable', 'string', 'max:120'],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'string', 'max:10'],
            'end_time' => ['nullable', 'string', 'max:10'],
            'duration_type' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::DURATION_TYPE)],
            'hours' => ['nullable', 'numeric', 'min:0.5'],
            'guest_count' => ['nullable', 'integer', 'min:0'],
            'seating' => ['nullable', 'string', 'max:1000'],
            'av_needs' => ['nullable', 'string', 'max:1000'],
            'decoration' => ['nullable', 'string', 'max:1000'],
            'catering_by_hotel' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'extras' => ['nullable', 'array'],
            'extras.*.description' => ['required', 'string', 'max:255'],
            'extras.*.amount' => ['required', 'integer', 'min:0'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }
}
