<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuItem extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'icon',
        'route_name',
        'module_key',
        'actions',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->orderBy('order');
    }

    public function isGroup(): bool
    {
        return $this->route_name === null;
    }

    /**
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        if ($this->module_key === null) {
            return [];
        }

        return array_map(
            fn (string $action): string => "{$this->module_key}.{$action}",
            $this->actions ?? [],
        );
    }
}
