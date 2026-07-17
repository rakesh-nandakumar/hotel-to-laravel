<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class AdjustIngredientStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_ingredients.adjust_stock') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'delta' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:500'],
            'expiry_date' => ['nullable', 'date'],
        ];
    }
}
