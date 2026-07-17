<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_orders.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_key' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(['room_guest', 'walkin'])],
            'dining_mode' => ['nullable', 'string', Rule::in(['dine_in', 'takeaway'])],
            'room_id' => ['required_if:type,room_guest', 'nullable', 'integer', 'exists:rooms,id'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:pos_menu_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
