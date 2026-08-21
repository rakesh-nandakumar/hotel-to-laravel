<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\InventoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyPermission(['hotel_ingredients.create', 'hotel_products.create']) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:ingredients,name'],
            'unit' => ['required', 'string', 'max:20'],
            'stock_qty' => ['required', 'numeric', 'min:0'],
            'low_stock_threshold' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'integer', 'min:0'],
            'kind' => ['required', 'string', Rule::in([InventoryKind::INGREDIENT, InventoryKind::PRODUCT])],
            'selling_price' => ['required_if:kind,product', 'nullable', 'integer', 'min:0'],
            'menu_category_id' => ['nullable', 'integer', 'exists:pos_menu_categories,id'],
            'image' => ['nullable', 'string', 'max:5000000'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
