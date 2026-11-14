<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Terisolasi penuh di dalam database SQLite modul PersonalApp.
     */
    protected $connection = 'personal_app';

    protected $table = 'users';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'google_id',
        'avatar',
        'pin',
        'phone_verified_at',
        'email_verified_at',
        'password',
    ];

    protected $hidden = [
        'pin',
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'pin' => 'hashed',
            'password' => 'hashed',
        ];
    }

    public function hasPin(): bool
    {
        return ! empty($this->pin);
    }
}
