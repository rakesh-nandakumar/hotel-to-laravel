<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddOnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_menu_items.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'price' => ['required', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'stock_ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'menu_item_ids' => ['nullable', 'array'],
            'menu_item_ids.*' => ['integer', 'exists:pos_menu_items,id'],
            'menu_category_ids' => ['nullable', 'array'],
            'menu_category_ids.*' => ['integer', 'exists:pos_menu_categories,id'],
        ];
    }
}
