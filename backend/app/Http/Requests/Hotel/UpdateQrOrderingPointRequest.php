<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQrOrderingPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_qr_ordering.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
