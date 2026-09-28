<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Permissions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Mirrors a model using spatie/laravel-permission's HasRoles trait.
 */
class Member extends Model
{
    // "users" sorts after "permissions", which is the order that exposed the bug.
    protected $table = 'users';

    public function permissions(): MorphToMany
    {
        return $this->morphToMany(Permission::class, 'model', 'model_has_permissions', 'model_id', 'permission_id');
    }

    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'model', 'model_has_roles', 'model_id', 'role_id');
    }
}
