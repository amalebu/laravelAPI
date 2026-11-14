<?php

namespace App\Modules\PersonalApp\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\PersonalApp\Http\Requests\Habit\LogHabitProgressRequest;
use App\Modules\PersonalApp\Http\Requests\Habit\StoreHabitRequest;
use App\Modules\PersonalApp\Http\Requests\Habit\UpdateHabitRequest;
use App\Modules\PersonalApp\Http\Resources\HabitResource;
use App\Modules\PersonalApp\Services\HabitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HabitController
{
    public function __construct(
        private readonly HabitService $habitService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $clientDate = $request->header('X-Client-Date');
        $result = $this->habitService->listForUser($request->user()->id, [
            'type' => $request->query('type'),
            'search' => $request->query('search'),
        ], $clientDate);

        return ApiResponse::success([
            'habits' => HabitResource::collection($result['habits']),
            'summary' => $result['summary'],
        ], 'Daftar habit berhasil diambil.');
    }

    public function store(StoreHabitRequest $request): JsonResponse
    {
        $habit = $this->habitService->createHabit($request->user()->id, $request->validated());

        return ApiResponse::created(
            new HabitResource($habit),
            'Kebiasaan baru berhasil ditambahkan.'
        );
    }

    public function update(UpdateHabitRequest $request, int $id): JsonResponse
    {
        $habit = $this->habitService->updateHabit($request->user()->id, $id, $request->validated());

        return ApiResponse::success(
            new HabitResource($habit),
            'Kebiasaan berhasil diperbarui.'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->habitService->deleteHabit($request->user()->id, $id);

        return ApiResponse::success(null, 'Kebiasaan berhasil dihapus.');
    }

    public function logProgress(LogHabitProgressRequest $request, int $id): JsonResponse
    {
        $clientDate = $request->header('X-Client-Date');
        $habit = $this->habitService->logProgress(
            $request->user()->id,
            $id,
            $request->validated(),
            $clientDate
        );

        return ApiResponse::success(
            new HabitResource($habit),
            'Progres kebiasaan berhasil dicatat.'
        );
    }
}
