<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Permission extends Model
{
    use SoftDeletes;

    protected $fillable = ['name'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    public function userOverrides(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permission_overrides')
            ->withPivot(['type', 'granted_by', 'granted_at']);
    }
}
