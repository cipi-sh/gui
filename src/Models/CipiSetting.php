<?php

namespace CipiGui\Models;

use Illuminate\Database\Eloquent\Model;

class CipiSetting extends Model
{
    protected $table = 'cipi_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
