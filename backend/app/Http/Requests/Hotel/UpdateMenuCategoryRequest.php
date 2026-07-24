<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_menu_categories.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150', Rule::unique('pos_menu_categories', 'name')->ignore($this->route('menuCategory'))],
            'sort_order' => ['sometimes', 'integer'],
            'is_minibar' => ['sometimes', 'boolean'],
            'kitchen_station' => ['sometimes', 'nullable', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::KITCHEN_STATION)],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
