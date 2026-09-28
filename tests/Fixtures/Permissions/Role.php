<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Permissions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Mirrors Spatie\Permission\Models\Role.
 */
class Role extends Model
{
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_has_permissions', 'role_id', 'permission_id');
    }

    public function members(): MorphToMany
    {
        return $this->morphedByMany(Member::class, 'model', 'model_has_roles', 'role_id', 'model_id');
    }
}
