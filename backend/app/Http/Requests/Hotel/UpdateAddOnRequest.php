<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddOnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_menu_items.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'send_to_kot' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'stock_ingredient_id' => ['sometimes', 'nullable', 'integer', 'exists:ingredients,id'],
            'menu_item_ids' => ['sometimes', 'array'],
            'menu_item_ids.*' => ['integer', 'exists:pos_menu_items,id'],
            'menu_category_ids' => ['sometimes', 'array'],
            'menu_category_ids.*' => ['integer', 'exists:pos_menu_categories,id'],
        ];
    }
}
