<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class SubmitVenueInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'client_name' => ['required', 'string'],
            'client_phone' => ['required', 'string', 'min:5'],
            'client_email' => ['nullable', 'string'],
            'event_type' => ['nullable', 'string'],
            'date' => ['required', 'date'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
