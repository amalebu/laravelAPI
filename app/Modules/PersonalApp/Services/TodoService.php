<?php

namespace App\Modules\PersonalApp\Services;

use App\Modules\PersonalApp\Models\Todo;
use App\Modules\PersonalApp\Models\TodoItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TodoService
{
    /**
     * Ambil daftar tugas user beserta ringkasan jumlah per kategori filter.
     */
    public function listForUser(int $userId, array $filters = []): array
    {
        $today = Carbon::now()->format('Y-m-d');
        $nowTime = Carbon::now()->format('H:i');

        $query = Todo::query()
            ->with('items')
            ->where('user_id', $userId);

        $filter = $filters['filter'] ?? 'all';

        if ($filter === 'today') {
            $query->where('is_completed', false)
                ->whereDate('due_date', '=', $today);
        } elseif ($filter === 'upcoming') {
            $query->where('is_completed', false)
                ->whereDate('due_date', '>', $today);
        } elseif ($filter === 'overdue') {
            $query->where('is_completed', false)
                ->whereNotNull('due_date')
                ->where(function ($q) use ($today, $nowTime) {
                    $q->whereDate('due_date', '<', $today)
                        ->orWhere(function ($q2) use ($today, $nowTime) {
                            $q2->whereDate('due_date', '=', $today)
                                ->whereNotNull('due_time')
                                ->where('due_time', '<', $nowTime);
                        });
                });
        } elseif ($filter === 'completed') {
            $query->where('is_completed', true);
        } elseif ($filter === 'active') {
            $query->where('is_completed', false);
        }

        if (! empty($filters['priority']) && in_array($filters['priority'], ['low', 'medium', 'high'], true)) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $todos = $query
            ->orderBy('is_completed', 'asc')
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END ASC")
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('due_date', 'asc')
            ->orderByRaw('CASE WHEN due_time IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('due_time', 'asc')
            ->orderBy('id', 'desc')
            ->get();

        return [
            'todos' => $todos,
            'counts' => $this->getCountsForUser($userId, $today, $nowTime),
        ];
    }

    /**
     * Hitung ringkasan jumlah tugas per filter untuk badge di UI.
     */
    public function getCountsForUser(int $userId, ?string $today = null, ?string $nowTime = null): array
    {
        $today = $today ?? Carbon::now()->format('Y-m-d');
        $nowTime = $nowTime ?? Carbon::now()->format('H:i');

        $allUserTodos = Todo::query()
            ->where('user_id', $userId)
            ->get(['id', 'due_date', 'due_time', 'is_completed']);

        $activeCount = 0;
        $todayCount = 0;
        $upcomingCount = 0;
        $overdueCount = 0;
        $completedCount = 0;

        foreach ($allUserTodos as $todo) {
            if ($todo->is_completed) {
                $completedCount++;
                continue;
            }

            $activeCount++;
            $dueStr = $todo->due_date ? $todo->due_date->format('Y-m-d') : null;

            if (! $dueStr) {
                continue;
            }

            if ($dueStr < $today || ($dueStr === $today && $todo->due_time && $todo->due_time < $nowTime)) {
                $overdueCount++;
            }

            if ($dueStr === $today) {
                $todayCount++;
            } elseif ($dueStr > $today) {
                $upcomingCount++;
            }
        }

        return [
            'all' => $allUserTodos->count(),
            'active' => $activeCount,
            'today' => $todayCount,
            'upcoming' => $upcomingCount,
            'overdue' => $overdueCount,
            'completed' => $completedCount,
        ];
    }

    /**
     * Buat tugas baru beserta daftar sub-checklist opsional.
     */
    public function createTodo(int $userId, array $data): Todo
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $data) {
            $todo = Todo::create([
                'user_id' => $userId,
                'title' => trim($data['title']),
                'description' => isset($data['description']) && trim($data['description']) !== ''
                    ? trim($data['description'])
                    : null,
                'priority' => $data['priority'] ?? 'medium',
                'due_date' => $data['due_date'] ?? null,
                'due_time' => ! empty($data['due_date']) ? ($data['due_time'] ?? null) : null,
                'is_completed' => false,
                'completed_at' => null,
            ]);

            if (! empty($data['items']) && is_array($data['items'])) {
                foreach (array_values($data['items']) as $index => $itemData) {
                    if (empty(trim($itemData['title'] ?? ''))) {
                        continue;
                    }
                    $todo->items()->create([
                        'title' => trim($itemData['title']),
                        'is_completed' => (bool) ($itemData['is_completed'] ?? false),
                        'sort_order' => $index,
                    ]);
                }

                $this->syncParentCompletionFromSubtasks($todo);
            }

            return $todo->load('items');
        });
    }

    /**
     * Perbarui detail tugas beserta sub-checklist.
     */
    public function updateTodo(int $userId, int $todoId, array $data): Todo
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $todoId, $data) {
            $todo = $this->findUserTodoOrFail($userId, $todoId);

            $dueDate = array_key_exists('due_date', $data) ? $data['due_date'] : $todo->due_date?->format('Y-m-d');
            $dueTime = ! empty($dueDate)
                ? (array_key_exists('due_time', $data) ? $data['due_time'] : $todo->due_time)
                : null;

            $todo->update([
                'title' => trim($data['title']),
                'description' => isset($data['description']) && trim($data['description']) !== ''
                    ? trim($data['description'])
                    : null,
                'priority' => $data['priority'] ?? $todo->priority,
                'due_date' => $dueDate,
                'due_time' => $dueTime,
            ]);

            if (array_key_exists('items', $data) && is_array($data['items'])) {
                $todo->items()->delete();

                foreach (array_values($data['items']) as $index => $itemData) {
                    if (empty(trim($itemData['title'] ?? ''))) {
                        continue;
                    }
                    $todo->items()->create([
                        'title' => trim($itemData['title']),
                        'is_completed' => (bool) ($itemData['is_completed'] ?? false),
                        'sort_order' => $index,
                    ]);
                }

                $this->syncParentCompletionFromSubtasks($todo);
            }

            return $todo->load('items');
        });
    }

    /**
     * Centang / batalkan centang tugas utama secara manual.
     */
    public function toggleTodo(int $userId, int $todoId): Todo
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $todoId) {
            $todo = $this->findUserTodoOrFail($userId, $todoId);
            $newState = ! $todo->is_completed;

            $todo->update([
                'is_completed' => $newState,
                'completed_at' => $newState ? Carbon::now() : null,
            ]);

            return $todo->load('items');
        });
    }

    /**
     * Hapus tugas beserta seluruh sub-checklist-nya.
     */
    public function deleteTodo(int $userId, int $todoId): void
    {
        DB::connection('personal_app')->transaction(function () use ($userId, $todoId) {
            $todo = $this->findUserTodoOrFail($userId, $todoId);
            $todo->delete();
        });
    }

    /**
     * Tambah cepat 1 item sub-checklist pada tugas utama.
     */
    public function addSubtask(int $userId, int $todoId, string $title): Todo
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $todoId, $title) {
            $todo = $this->findUserTodoOrFail($userId, $todoId);
            $nextOrder = ((int) $todo->items()->max('sort_order')) + 1;

            $todo->items()->create([
                'title' => trim($title),
                'is_completed' => false,
                'sort_order' => $nextOrder,
            ]);

            $this->syncParentCompletionFromSubtasks($todo);

            return $todo->load('items');
        });
    }

    /**
     * Toggle centang pada item sub-checklist dan otomatis sinkronkan status selesai tugas utama.
     */
    public function toggleSubtask(int $userId, int $todoId, int $itemId): Todo
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $todoId, $itemId) {
            $todo = $this->findUserTodoOrFail($userId, $todoId);

            /** @var TodoItem $item */
            $item = $todo->items()->where('id', $itemId)->firstOrFail();
            $item->update([
                'is_completed' => ! $item->is_completed,
            ]);

            $this->syncParentCompletionFromSubtasks($todo);

            return $todo->load('items');
        });
    }

    /**
     * Hapus 1 item sub-checklist dan sinkronkan status tugas utama.
     */
    public function deleteSubtask(int $userId, int $todoId, int $itemId): Todo
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $todoId, $itemId) {
            $todo = $this->findUserTodoOrFail($userId, $todoId);
            $item = $todo->items()->where('id', $itemId)->firstOrFail();
            $item->delete();

            $this->syncParentCompletionFromSubtasks($todo);

            return $todo->load('items');
        });
    }

    /**
     * Otomatis selesaikan tugas utama saat semua sub-checklist dicentang,
     * atau buka kembali jika ada sub-checklist yang belum selesai.
     */
    private function syncParentCompletionFromSubtasks(Todo $todo): void
    {
        $items = $todo->items()->get(['id', 'is_completed']);

        if ($items->isEmpty()) {
            return;
        }

        $allCompleted = $items->every(fn (TodoItem $item) => (bool) $item->is_completed);

        if ($allCompleted && ! $todo->is_completed) {
            $todo->update([
                'is_completed' => true,
                'completed_at' => Carbon::now(),
            ]);
        } elseif (! $allCompleted && $todo->is_completed) {
            $todo->update([
                'is_completed' => false,
                'completed_at' => null,
            ]);
        }
    }

    private function findUserTodoOrFail(int $userId, int $todoId): Todo
    {
        return Todo::query()
            ->where('user_id', $userId)
            ->where('id', $todoId)
            ->firstOrFail();
    }
}
