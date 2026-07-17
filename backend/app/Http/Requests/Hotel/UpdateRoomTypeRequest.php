<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_room_types.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $roomType = $this->route('roomType');

        return [
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('room_types', 'name')->ignore($roomType)],
            'max_occupancy' => ['sometimes', 'integer', 'min:1'],
            'bed_config' => ['nullable', 'string', 'max:255'],
            'amenities' => ['array'],
            'amenities.*' => ['string', 'max:120'],
            'weekday_rate' => ['sometimes', 'integer', 'min:0'],
            'weekend_rate' => ['sometimes', 'integer', 'min:0'],
            'item_checklist' => ['array'],
            'item_checklist.*' => ['string', 'max:255'],
            'cleaning_checklist' => ['array'],
            'cleaning_checklist.*' => ['string', 'max:255'],
        ];
    }
}
