<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_rooms.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:20', Rule::unique('rooms', 'number')],
            'name' => ['nullable', 'string', 'max:120'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
            'floor' => ['nullable', 'string', 'max:50'],
            'view' => ['nullable', 'string', 'max:50'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:120'],
            'max_occupancy' => ['nullable', 'integer', 'min:1', 'required_without:room_type_id'],
            'bed_config' => ['nullable', 'string', 'max:255'],
            'weekday_rate' => ['nullable', 'integer', 'min:0', 'required_without:room_type_id'],
            'weekend_rate' => ['nullable', 'integer', 'min:0', 'required_without:room_type_id'],
            'item_checklist' => ['nullable', 'array'],
            'item_checklist.*' => ['string', 'max:255'],
            'cleaning_checklist' => ['nullable', 'array'],
            'cleaning_checklist.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
