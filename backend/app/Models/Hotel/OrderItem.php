<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id',

        'order_id',
        'menu_item_id',
        'add_on_id',
        'product_id',
        'name',
        'send_to_kot',
        'qty',
        'unit_price',
        'amount',
        'notes',
        'voided',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'send_to_kot' => 'boolean',
            'qty' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
            'voided' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'product_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }
}
