<?php

namespace App\Http\Requests\Apartment;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_sales.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required_without:new_customer', 'nullable', 'integer', 'exists:apartment_customers,id'],
            'new_customer' => ['required_without:customer_id', 'nullable', 'array'],
            'new_customer.name' => ['required_with:new_customer', 'string', 'max:150'],
            'new_customer.phone' => ['nullable', 'string', 'max:30'],
            'new_customer.email' => ['nullable', 'string', 'email', 'max:255'],
            'new_customer.is_company' => ['nullable', 'boolean'],
            'new_customer.company_name' => ['nullable', 'string', 'max:150'],

            'unit_id' => ['required', 'integer', 'exists:apartment_units,id'],
            'agreed_price' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
