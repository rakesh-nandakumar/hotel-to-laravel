<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public, unauthenticated — a guest placing an order after scanning a room
 * or table QR code. Only shape is validated here; business rules (table
 * availability, whether a name/phone is required) live in
 * QrOrderingService, matching StoreOrderRequest/OrderService's own split.
 */
class PlaceQrOrderRequest extends FormRequest
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
            'client_key' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:pos_menu_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'exists:menu_item_modifiers,id'],
        ];
    }
}
