<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Todo extends BaseModel
{
    protected $table = 'todos';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'priority',
        'due_date',
        'due_time',
        'is_completed',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TodoItem::class, 'todo_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
