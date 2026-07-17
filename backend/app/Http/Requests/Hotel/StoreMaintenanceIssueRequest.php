<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_maintenance.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required_without:venue_id', 'nullable', 'integer', 'exists:rooms,id'],
            'venue_id' => ['required_without:room_id', 'nullable', 'integer', 'exists:venues,id'],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'take_room_out_of_service' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required_without' => 'Issue must be against a room or a venue.',
            'venue_id.required_without' => 'Issue must be against a room or a venue.',
        ];
    }
}
