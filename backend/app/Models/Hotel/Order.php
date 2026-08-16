<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Lookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id',

        'client_key',
        'order_type_id',
        'dining_mode_id',
        'order_status_id',
        'kot_status_id',
        'kot_started_at',
        'kot_ready_at',
        'served_at',
        'room_id',
        'dining_table_id',
        'delivery_address',
        'delivery_phone',
        'delivery_rider_id',
        'delivery_status_id',
        'dispatched_at',
        'delivered_at',
        'parent_order_id',
        'reservation_id',
        'customer_name',
        'customer_phone',
        'placed_via_qr',
        'notes',
        'subtotal',
        'discount',
        'discount_reason',
        'discount_by_id',
        'service_charge',
        'vat',
        'total',
        'staff_id',
        'settled_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'placed_via_qr' => 'boolean',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'service_charge' => 'integer',
            'vat' => 'integer',
            'total' => 'integer',
            'settled_at' => 'datetime',
            'kot_started_at' => 'datetime',
            'kot_ready_at' => 'datetime',
            'served_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'order_type_id');
    }

    public function diningMode(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'dining_mode_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'order_status_id');
    }

    public function kotStatus(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'kot_status_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function deliveryRider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_rider_id');
    }

    public function deliveryStatus(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'delivery_status_id');
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    /** Child orders created by OrderService::splitBill(), or the orders folded into this one by mergeOrders(). */
    public function childOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function discountBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function folioLines(): HasMany
    {
        return $this->hasMany(FolioLine::class);
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }

    /**
     * @param  Builder<Order>  $query
     * @param  list<string>  $codes
     */
    public function scopeStatusIn(Builder $query, array $codes): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->whereIn('code', $codes));
    }
}
