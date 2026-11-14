<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitLog extends BaseModel
{
    protected $table = 'habit_logs';

    protected $fillable = [
        'habit_id',
        'user_id',
        'logged_date',
        'value',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'logged_date' => 'date:Y-m-d',
            'value' => 'float',
        ];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class, 'habit_id');
    }
}
