<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuItemRequest extends FormRequest
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
            'menu_category_id' => ['sometimes', 'integer', 'exists:pos_menu_categories,id'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'item_no' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::unique('pos_menu_items', 'item_no')->ignore($this->route('menuItem'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
            'recipe' => ['sometimes', 'array'],
            'recipe.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'recipe.*.qty' => ['required', 'numeric', 'min:0'],
        ];
    }
}
