<?php

namespace App\Modules\PersonalApp\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HabitLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'habit_id' => $this->habit_id,
            'logged_date' => $this->logged_date?->format('Y-m-d'),
            'value' => round((float) $this->value, 2),
            'note' => $this->note,
        ];
    }
}
