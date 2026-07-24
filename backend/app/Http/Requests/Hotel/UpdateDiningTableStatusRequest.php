<?php

namespace App\Http\Requests\Hotel;

use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDiningTableStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_dining_tables.edit_status') ?? false;
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
                Rule::exists('lookups', 'code')->where('type', LookupType::TABLE_STATUS),
            ],
        ];
    }

    public function statusLookup(): Lookup
    {
        return Lookup::query()->type(LookupType::TABLE_STATUS)->where('code', $this->string('status'))->firstOrFail();
    }
}
