<?php

namespace AISeeder\Tests\Fixtures\Models;

use AISeeder\Tests\Fixtures\Enums\UserStatus;
use AISeeder\Tests\Fixtures\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'password' => 'hashed',
        ];
    }

    // No return type: detected from the method body, as model:show does.
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class);
    }

    public function broken()
    {
        return $this->hasMany('Missing\\ClassName');
    }
}
