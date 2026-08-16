<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOrderItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['nullable', 'integer', 'exists:pos_menu_items,id'],
            'items.*.add_on_id' => ['nullable', 'integer', 'exists:add_ons,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'exists:menu_item_modifiers,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach ($validator->getData()['items'] ?? [] as $i => $line) {
                $hasItem = ! empty($line['menu_item_id']);
                $hasAddOn = ! empty($line['add_on_id']);
                if ($hasItem === $hasAddOn) {
                    $validator->errors()->add("items.$i", 'Each line must have exactly one of menu_item_id or add_on_id.');
                }
            }
        });
    }
}
