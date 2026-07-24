<?php

namespace App\Http\Requests\Apartment;

use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_units.edit_status') ?? false;
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
                Rule::exists('lookups', 'code')->where('type', LookupType::APARTMENT_UNIT_STATUS),
            ],
        ];
    }

    public function statusLookup(): Lookup
    {
        return Lookup::query()->type(LookupType::APARTMENT_UNIT_STATUS)->where('code', $this->string('status'))->firstOrFail();
    }
}
