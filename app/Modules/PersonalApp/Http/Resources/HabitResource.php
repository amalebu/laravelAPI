<?php

namespace App\Modules\PersonalApp\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class HabitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $todayStr = $request->header('X-Client-Date')
            ?: ($request->input('logged_date') ?: Carbon::now(config('app.timezone', 'Asia/Jakarta'))->format('Y-m-d'));
        $logsCollection = $this->whenLoaded('logs', fn () => $this->logs, collect());

        $todayLog = $logsCollection->first(
            fn ($log) => $log->logged_date?->format('Y-m-d') === $todayStr
        );
        $todayValue = $todayLog ? round((float) $todayLog->value, 2) : 0.0;
        $target = max(0.0, (float) $this->target_value);

        $isTodayGoalMet = false;
        if ($this->type === 'good') {
            $isTodayGoalMet = $target > 0 ? $todayValue >= $target : $todayValue > 0;
        } else {
            // Untuk bad habit: hari bersih/terkendali jika value <= target_value
            $isTodayGoalMet = $todayValue <= $target;
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'icon' => $this->icon,
            'unit' => $this->unit,
            'target_value' => round((float) $this->target_value, 2),
            'step_value' => round((float) $this->step_value, 2),
            'current_streak' => (int) $this->current_streak,
            'longest_streak' => (int) $this->longest_streak,
            'is_active' => (bool) $this->is_active,
            'today_value' => $todayValue,
            'is_today_goal_met' => $isTodayGoalMet,
            'logs' => HabitLogResource::collection($this->whenLoaded('logs')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
