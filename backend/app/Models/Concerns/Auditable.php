<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-stamps created_by / updated_by / deleted_by on lifecycle events.
 *
 * Composes with SoftDeletes — the deleted_by stamp is written on soft delete
 * only. Hard deletes (force-delete) are not stamped because the row is gone.
 *
 * Designed to compose with a future `BelongsToTenant` trait without conflict.
 * Keep this trait minimal; structured audit-log writes happen in services,
 * not here, so we don't double-log on every save.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::creating(function ($model): void {
            if (Auth::check()) {
                $model->created_by ??= Auth::id();
                $model->updated_by ??= Auth::id();
            }
        });

        static::updating(function ($model): void {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function ($model): void {
            if (! Auth::check()) {
                return;
            }

            $usesSoftDeletes = method_exists($model, 'isForceDeleting');
            if ($usesSoftDeletes && ! $model->isForceDeleting()) {
                // Save the actor on the row before the soft-delete completes
                $model->deleted_by = Auth::id();
                $model->saveQuietly();
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

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
