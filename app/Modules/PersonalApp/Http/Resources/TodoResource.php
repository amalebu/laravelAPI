<?php

namespace App\Modules\PersonalApp\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class TodoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items', fn () => $this->items, collect());
        $subtasksTotal = $items->count();
        $subtasksCompleted = $items->where('is_completed', true)->count();

        $dueDateStr = $this->due_date ? $this->due_date->format('Y-m-d') : null;
        $isOverdue = false;

        if (! $this->is_completed && $dueDateStr) {
            $todayStr = Carbon::now()->format('Y-m-d');
            if ($dueDateStr < $todayStr) {
                $isOverdue = true;
            } elseif ($dueDateStr === $todayStr && $this->due_time) {
                $nowTime = Carbon::now()->format('H:i');
                if ($this->due_time < $nowTime) {
                    $isOverdue = true;
                }
            }
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'due_date' => $dueDateStr,
            'due_time' => $this->due_time,
            'is_completed' => (bool) $this->is_completed,
            'is_overdue' => $isOverdue,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'subtasks_total' => $subtasksTotal,
            'subtasks_completed' => $subtasksCompleted,
            'items' => TodoItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
