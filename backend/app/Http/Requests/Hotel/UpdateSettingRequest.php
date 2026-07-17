<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_settings.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Type-specific validation (number/percent/boolean/json shape) happens
            // in Settings::set() — it already knows each key's declared type.
            'value' => ['present'],
        ];
    }
}
