<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Habit extends BaseModel
{
    protected $table = 'habits';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'type',
        'icon',
        'unit',
        'target_value',
        'step_value',
        'current_streak',
        'longest_streak',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'float',
            'step_value' => 'float',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class, 'habit_id')
            ->orderBy('logged_date', 'asc');
    }
}
