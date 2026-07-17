<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Database\Factories\Hotel\CorporateAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CorporateAccount extends Model
{
    use HasFactory, HasUserstamps, SoftDeletes;

    protected static function newFactory(): CorporateAccountFactory
    {
        return CorporateAccountFactory::new();
    }

    protected $fillable = [
        'company_name',
        'contact_name',
        'phone',
        'email',
        'address',
        'discount_pct',
        'credit_limit',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_pct' => 'float',
            'credit_limit' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<CorporateAccount>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** Month-end settlement payments — see phase3-progress memory for the deferred statement/settle endpoints. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
