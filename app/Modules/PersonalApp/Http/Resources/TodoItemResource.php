<?php

namespace App\Modules\PersonalApp\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TodoItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'todo_id' => $this->todo_id,
            'title' => $this->title,
            'is_completed' => (bool) $this->is_completed,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
