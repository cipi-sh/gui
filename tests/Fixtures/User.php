<?php

namespace CipiGui\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
        ];
    }
}
