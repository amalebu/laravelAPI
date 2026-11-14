<?php

namespace App\Modules\PersonalApp\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TodoItem extends BaseModel
{
    protected $table = 'todo_items';

    protected $fillable = [
        'todo_id',
        'title',
        'is_completed',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class, 'todo_id');
    }
}
