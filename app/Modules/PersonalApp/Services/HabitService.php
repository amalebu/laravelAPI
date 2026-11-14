<?php

namespace App\Modules\PersonalApp\Services;

use App\Modules\PersonalApp\Models\Habit;
use App\Modules\PersonalApp\Models\HabitLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HabitService
{
    public function listForUser(int $userId, array $filters = [], ?string $clientDate = null): array
    {
        $today = $clientDate ? Carbon::parse($clientDate) : Carbon::now(config('app.timezone', 'Asia/Jakarta'));
        $cutoffDate = $today->copy()->subDays(366)->format('Y-m-d');

        $query = Habit::query()
            ->with(['logs' => function ($q) use ($cutoffDate) {
                $q->whereDate('logged_date', '>=', $cutoffDate)
                    ->orderBy('logged_date', 'asc');
            }])
            ->where('user_id', $userId);

        if (! empty($filters['type']) && in_array($filters['type'], ['good', 'bad'], true)) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $habits = $query
            ->orderBy('is_active', 'desc')
            ->orderByRaw("CASE type WHEN 'good' THEN 1 ELSE 2 END ASC")
            ->orderBy('id', 'asc')
            ->get();

        return [
            'habits' => $habits,
            'summary' => $this->getSummaryForUser($userId, $clientDate),
        ];
    }

    public function getSummaryForUser(int $userId, ?string $clientDate = null): array
    {
        $todayStr = $clientDate ?: Carbon::now(config('app.timezone', 'Asia/Jakarta'))->format('Y-m-d');

        $allHabits = Habit::query()
            ->with(['logs' => function ($q) use ($todayStr) {
                $q->whereDate('logged_date', '=', $todayStr);
            }])
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        $goodCount = 0;
        $badCount = 0;
        $goodCompletedToday = 0;
        $badCleanToday = 0;
        $bestStreak = 0;

        foreach ($allHabits as $habit) {
            $todayLog = $habit->logs->first();
            $todayVal = $todayLog ? (float) $todayLog->value : 0.0;
            $target = max(0.0, (float) $habit->target_value);

            if ($habit->type === 'good') {
                $goodCount++;
                if (($target > 0 && $todayVal >= $target) || ($target == 0.0 && $todayVal > 0)) {
                    $goodCompletedToday++;
                }
            } else {
                $badCount++;
                if ($todayVal <= $target) {
                    $badCleanToday++;
                }
            }

            if ($habit->current_streak > $bestStreak) {
                $bestStreak = (int) $habit->current_streak;
            }
        }

        return [
            'total' => $allHabits->count(),
            'good_count' => $goodCount,
            'bad_count' => $badCount,
            'good_completed_today' => $goodCompletedToday,
            'bad_clean_today' => $badCleanToday,
            'best_streak' => $bestStreak,
        ];
    }

    public function createHabit(int $userId, array $data): Habit
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $data) {
            $type = $data['type'] ?? 'good';
            $defaultIcon = $type === 'good' ? 'droplets' : 'ban';

            $habit = Habit::create([
                'user_id' => $userId,
                'name' => trim($data['name']),
                'description' => isset($data['description']) && trim($data['description']) !== ''
                    ? trim($data['description'])
                    : null,
                'type' => $type,
                'icon' => $data['icon'] ?? $defaultIcon,
                'unit' => trim($data['unit'] ?? 'kali'),
                'target_value' => (float) ($data['target_value'] ?? ($type === 'good' ? 1 : 0)),
                'step_value' => max(0.01, (float) ($data['step_value'] ?? 1)),
                'current_streak' => $type === 'bad' ? 1 : 0,
                'longest_streak' => $type === 'bad' ? 1 : 0,
                'is_active' => true,
            ]);

            return $habit->load('logs');
        });
    }

    public function updateHabit(int $userId, int $habitId, array $data): Habit
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $habitId, $data) {
            $habit = $this->findUserHabitOrFail($userId, $habitId);

            $habit->update([
                'name' => trim($data['name']),
                'description' => isset($data['description']) && trim($data['description']) !== ''
                    ? trim($data['description'])
                    : null,
                'type' => $data['type'] ?? $habit->type,
                'icon' => $data['icon'] ?? $habit->icon,
                'unit' => trim($data['unit'] ?? $habit->unit),
                'target_value' => (float) ($data['target_value'] ?? $habit->target_value),
                'step_value' => max(0.01, (float) ($data['step_value'] ?? $habit->step_value)),
                'is_active' => array_key_exists('is_active', $data)
                    ? (bool) $data['is_active']
                    : $habit->is_active,
            ]);

            $this->recalculateStreaks($habit);

            return $habit->load('logs');
        });
    }

    public function deleteHabit(int $userId, int $habitId): void
    {
        DB::connection('personal_app')->transaction(function () use ($userId, $habitId) {
            $habit = $this->findUserHabitOrFail($userId, $habitId);
            $habit->delete();
        });
    }

    public function logProgress(int $userId, int $habitId, array $data, ?string $clientDate = null): Habit
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $habitId, $data, $clientDate) {
            $habit = $this->findUserHabitOrFail($userId, $habitId);
            $loggedDate = $data['logged_date'] ?? ($clientDate ?: Carbon::now(config('app.timezone', 'Asia/Jakarta'))->format('Y-m-d'));
            $mode = $data['mode'] ?? 'increment';
            $inputValue = (float) $data['value'];

            /** @var HabitLog|null $existingLog */
            $existingLog = HabitLog::query()
                ->where('habit_id', $habit->id)
                ->whereDate('logged_date', '=', $loggedDate)
                ->first();

            $currentValue = $existingLog ? (float) $existingLog->value : 0.0;
            $newValue = $mode === 'set'
                ? max(0.0, round($inputValue, 2))
                : max(0.0, round($currentValue + $inputValue, 2));

            if ($existingLog) {
                $existingLog->update([
                    'value' => $newValue,
                    'note' => array_key_exists('note', $data) ? $data['note'] : $existingLog->note,
                ]);
            } else {
                HabitLog::create([
                    'habit_id' => $habit->id,
                    'user_id' => $userId,
                    'logged_date' => $loggedDate,
                    'value' => $newValue,
                    'note' => $data['note'] ?? null,
                ]);
            }

            $this->recalculateStreaks($habit, $clientDate ?: $loggedDate);

            return $habit->load('logs');
        });
    }

    public function recalculateStreaks(Habit $habit, ?string $clientDate = null): void
    {
        $logs = HabitLog::query()
            ->where('habit_id', $habit->id)
            ->orderBy('logged_date', 'asc')
            ->get();

        $logMap = [];
        foreach ($logs as $log) {
            $dateStr = $log->logged_date?->format('Y-m-d');
            if ($dateStr) {
                $logMap[$dateStr] = (float) $log->value;
            }
        }

        $today = $clientDate
            ? Carbon::parse($clientDate)->startOfDay()
            : Carbon::now(config('app.timezone', 'Asia/Jakarta'))->startOfDay();
        $todayStr = $today->format('Y-m-d');
        $target = max(0.0, (float) $habit->target_value);

        if ($habit->type === 'good') {
            $isSuccess = fn (float $val): bool => $target > 0 ? $val >= $target : $val > 0;

            // Hitung current streak (mundur dari hari ini, atau dari kemarin jika hari ini belum penuh)
            $currentStreak = 0;
            $cursor = $today->copy();
            $todayVal = $logMap[$todayStr] ?? 0.0;

            if (! $isSuccess($todayVal)) {
                $cursor->subDay();
            }

            while (true) {
                $dStr = $cursor->format('Y-m-d');
                $val = $logMap[$dStr] ?? 0.0;
                if ($isSuccess($val)) {
                    $currentStreak++;
                    $cursor->subDay();
                } else {
                    break;
                }
            }

            // Hitung longest streak sepanjang riwayat
            $longestStreak = $currentStreak;
            if (! empty($logMap)) {
                $dates = array_keys($logMap);
                sort($dates);
                $running = 0;
                $prevSuccessDate = null;

                foreach ($dates as $dStr) {
                    if (! $isSuccess($logMap[$dStr])) {
                        $running = 0;
                        $prevSuccessDate = null;
                        continue;
                    }

                    $currDate = Carbon::parse($dStr)->startOfDay();
                    if ($prevSuccessDate && $prevSuccessDate->diffInDays($currDate) === 1.0) {
                        $running++;
                    } else {
                        $running = 1;
                    }
                    $prevSuccessDate = $currDate;
                    if ($running > $longestStreak) {
                        $longestStreak = $running;
                    }
                }
            }

            $habit->update([
                'current_streak' => $currentStreak,
                'longest_streak' => max((int) $habit->longest_streak, $longestStreak),
            ]);

            return;
        }

        // Bad Habit: Clean Streak = hari berturut-turut di mana value <= target_value
        $earliestDate = $habit->created_at ? $habit->created_at->copy()->startOfDay() : $today->copy();
        if (! empty($logMap)) {
            $firstLogDate = Carbon::parse(min(array_keys($logMap)))->startOfDay();
            if ($firstLogDate->lt($earliestDate)) {
                $earliestDate = $firstLogDate;
            }
        }

        $currentStreak = 0;
        $longestStreak = 0;
        $running = 0;
        $cursor = $earliestDate->copy();

        while ($cursor->lte($today)) {
            $dStr = $cursor->format('Y-m-d');
            $val = $logMap[$dStr] ?? 0.0;

            if ($val <= $target) {
                $running++;
                if ($running > $longestStreak) {
                    $longestStreak = $running;
                }
            } else {
                $running = 0;
            }

            $cursor->addDay();
        }

        $currentStreak = $running;

        $habit->update([
            'current_streak' => $currentStreak,
            'longest_streak' => max((int) $habit->longest_streak, $longestStreak),
        ]);
    }

    private function findUserHabitOrFail(int $userId, int $habitId): Habit
    {
        return Habit::query()
            ->where('user_id', $userId)
            ->where('id', $habitId)
            ->firstOrFail();
    }
}
