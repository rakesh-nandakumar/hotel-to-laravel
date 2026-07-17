<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class ChargeLaundryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_laundry.charge') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.laundry_item_id' => ['required', 'integer', 'exists:laundry_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
