<?php

namespace CipiGui\Models;

use Illuminate\Database\Eloquent\Model;

class CipiAppGrant extends Model
{
    protected $table = 'cipi_app_grants';

    protected $fillable = ['user_id', 'server_id', 'app', 'abilities'];

    protected function casts(): array
    {
        return ['abilities' => 'array'];
    }
}
