<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    /**
     * Seluruh model pada modul PersonalApp terhubung ke database SQLite personal_app.
     */
    protected $connection = 'personal_app';
}
