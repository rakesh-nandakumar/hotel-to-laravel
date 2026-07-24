<?php

namespace App\Http\Requests\Apartment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLedgerLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_ledgers.add_line') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => [
                'required', 'string', Rule::in(['cleaning_fee', 'extra_guest_fee', 'utility', 'damage', 'adjustment', 'surcharge']),
            ],
            'description' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'integer'],
        ];
    }
}
