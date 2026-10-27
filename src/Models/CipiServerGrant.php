<?php

namespace CipiGui\Models;

use Illuminate\Database\Eloquent\Model;

class CipiServerGrant extends Model
{
    protected $table = 'cipi_server_grants';

    protected $fillable = ['user_id', 'server_id', 'level'];
}
