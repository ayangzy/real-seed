<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Permissions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Mirrors Spatie\Permission\Models\Permission.
 */
class Permission extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_has_permissions', 'permission_id', 'role_id');
    }

    // The inverse side: morphedByMany over the same pivot.
    public function members(): MorphToMany
    {
        return $this->morphedByMany(Member::class, 'model', 'model_has_permissions', 'permission_id', 'model_id');
    }
}
