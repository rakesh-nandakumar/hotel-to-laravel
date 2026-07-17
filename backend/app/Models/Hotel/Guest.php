<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Database\Factories\Hotel\GuestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guest extends Model
{
    use HasFactory, HasUserstamps, SoftDeletes;

    protected static function newFactory(): GuestFactory
    {
        return GuestFactory::new();
    }

    protected $fillable = [
        'name',
        'email',
        'phone',
        'id_number',
        'nationality',
        'preferences',
        'loyalty_points',
        'lifetime_spend',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'loyalty_points' => 'integer',
            'lifetime_spend' => 'integer',
        ];
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Rooms within a group booking billed to this guest instead of the
     * reservation's nominal guest.
     */
    public function billedRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class, 'bill_to_guest_id');
    }

    /**
     * @param  Builder<Guest>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('id_number', 'like', "%{$term}%");
        });
    }
}
