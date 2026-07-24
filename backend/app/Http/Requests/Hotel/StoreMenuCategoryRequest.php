<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_menu_categories.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:pos_menu_categories,name'],
            'sort_order' => ['nullable', 'integer'],
            'is_minibar' => ['nullable', 'boolean'],
            'kitchen_station' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::KITCHEN_STATION)],
        ];
    }
}
