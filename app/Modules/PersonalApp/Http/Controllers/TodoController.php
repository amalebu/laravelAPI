<?php

namespace App\Modules\PersonalApp\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\PersonalApp\Http\Requests\Todo\StoreTodoItemRequest;
use App\Modules\PersonalApp\Http\Requests\Todo\StoreTodoRequest;
use App\Modules\PersonalApp\Http\Requests\Todo\UpdateTodoRequest;
use App\Modules\PersonalApp\Http\Resources\TodoResource;
use App\Modules\PersonalApp\Services\TodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoController
{
    public function __construct(
        private readonly TodoService $todoService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->todoService->listForUser($request->user()->id, [
            'filter' => $request->query('filter', 'all'),
            'priority' => $request->query('priority'),
            'search' => $request->query('search'),
        ]);

        return ApiResponse::success([
            'todos' => TodoResource::collection($result['todos']),
            'counts' => $result['counts'],
        ], 'Daftar tugas berhasil diambil.');
    }

    public function store(StoreTodoRequest $request): JsonResponse
    {
        $todo = $this->todoService->createTodo($request->user()->id, $request->validated());

        return ApiResponse::created(
            new TodoResource($todo),
            'Tugas baru berhasil ditambahkan.'
        );
    }

    public function update(UpdateTodoRequest $request, int $id): JsonResponse
    {
        $todo = $this->todoService->updateTodo($request->user()->id, $id, $request->validated());

        return ApiResponse::success(
            new TodoResource($todo),
            'Tugas berhasil diperbarui.'
        );
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $todo = $this->todoService->toggleTodo($request->user()->id, $id);

        return ApiResponse::success(
            new TodoResource($todo),
            $todo->is_completed ? 'Tugas ditandai selesai.' : 'Tugas dikembalikan ke daftar aktif.'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->todoService->deleteTodo($request->user()->id, $id);

        return ApiResponse::success(null, 'Tugas berhasil dihapus.');
    }

    public function storeItem(StoreTodoItemRequest $request, int $id): JsonResponse
    {
        $todo = $this->todoService->addSubtask(
            $request->user()->id,
            $id,
            $request->validated('title')
        );

        return ApiResponse::created(
            new TodoResource($todo),
            'Sub-checklist berhasil ditambahkan.'
        );
    }

    public function toggleItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $todo = $this->todoService->toggleSubtask($request->user()->id, $id, $itemId);

        return ApiResponse::success(
            new TodoResource($todo),
            'Status sub-checklist berhasil diperbarui.'
        );
    }

    public function destroyItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $todo = $this->todoService->deleteSubtask($request->user()->id, $id, $itemId);

        return ApiResponse::success(
            new TodoResource($todo),
            'Sub-checklist berhasil dihapus.'
        );
    }
}
