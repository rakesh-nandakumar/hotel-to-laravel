<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_rooms.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $room = $this->route('room');

        return [
            'number' => ['sometimes', 'string', 'max:20', Rule::unique('rooms', 'number')->ignore($room)],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'room_type_id' => ['sometimes', 'nullable', 'integer', 'exists:room_types,id'],
            'branch_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'floor' => ['nullable', 'string', 'max:50'],
            'view' => ['nullable', 'string', 'max:50'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:120'],
            'max_occupancy' => ['sometimes', 'integer', 'min:1'],
            'bed_config' => ['nullable', 'string', 'max:255'],
            'weekday_rate' => ['sometimes', 'integer', 'min:0'],
            'weekend_rate' => ['sometimes', 'integer', 'min:0'],
            'item_checklist' => ['nullable', 'array'],
            'item_checklist.*' => ['string', 'max:255'],
            'cleaning_checklist' => ['nullable', 'array'],
            'cleaning_checklist.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
