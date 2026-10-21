<?php

namespace App\Exceptions;

use App\Http\Resources\PendingTaskResource;
use App\Models\PendingTask;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class OpenTasksConflict extends RuntimeException
{
    /** @param Collection<int, PendingTask> $tasks */
    public function __construct(public readonly Collection $tasks)
    {
        parent::__construct('Há pendências abertas. Confirme explicitamente para encerrar o atendimento.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'open_pending_tasks',
            'open_tasks_count' => $this->tasks->count(),
            'pending_tasks' => PendingTaskResource::collection($this->tasks)->resolve(),
        ], 409);
    }
}
