<?php

namespace App\Http\Requests\Hotel;

use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderDeliveryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_orders.delivery_dispatch') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::exists('lookups', 'code')->where('type', LookupType::DELIVERY_STATUS),
            ],
        ];
    }

    public function statusLookup(): Lookup
    {
        return Lookup::query()->type(LookupType::DELIVERY_STATUS)->where('code', $this->string('status'))->firstOrFail();
    }
}
