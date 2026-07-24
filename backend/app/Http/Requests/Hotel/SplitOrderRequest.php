<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class SplitOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_orders.split') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Each group becomes its own child order — every non-voided item
            // on the parent must appear in exactly one group (enforced in
            // OrderService::splitBill()).
            'groups' => ['required', 'array', 'min:2'],
            'groups.*' => ['required', 'array', 'min:1'],
            'groups.*.*' => ['integer', 'distinct'],
        ];
    }
}
