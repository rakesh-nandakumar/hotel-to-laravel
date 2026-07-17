<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderKotStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_orders.kot') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::KOT_STATUS)],
        ];
    }
}
