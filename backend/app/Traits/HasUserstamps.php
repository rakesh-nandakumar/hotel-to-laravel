<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait HasUserstamps
{
    public static function bootHasUserstamps(): void
    {
        static::creating(function ($model): void {
            if (Auth::check()) {
                $model->created_by ??= Auth::id();
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model): void {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
