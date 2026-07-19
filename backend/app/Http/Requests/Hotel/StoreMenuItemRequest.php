<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuItemRequest extends FormRequest
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
            'menu_category_id' => ['required', 'integer', 'exists:pos_menu_categories,id'],
            'price' => ['required', 'integer', 'min:0'],
            'item_no' => ['nullable', 'integer', 'min:1', 'unique:pos_menu_items,item_no'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'string', 'max:5000000'],
            'active' => ['nullable', 'boolean'],
            'recipe' => ['nullable', 'array'],
            'recipe.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'recipe.*.qty' => ['required', 'numeric', 'min:0'],
        ];
    }
}
