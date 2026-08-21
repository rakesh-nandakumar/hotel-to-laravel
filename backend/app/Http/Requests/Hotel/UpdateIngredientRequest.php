<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\InventoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyPermission(['hotel_ingredients.edit', 'hotel_products.edit']) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150', Rule::unique('ingredients', 'name')->ignore($this->route('ingredient'))],
            'unit' => ['sometimes', 'string', 'max:20'],
            'stock_qty' => ['sometimes', 'numeric', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'numeric', 'min:0'],
            'unit_cost' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'kind' => ['sometimes', 'string', Rule::in([InventoryKind::INGREDIENT, InventoryKind::PRODUCT])],
            'selling_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'menu_category_id' => ['sometimes', 'nullable', 'integer', 'exists:pos_menu_categories,id'],
            'image' => ['sometimes', 'nullable', 'string', 'max:5000000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
