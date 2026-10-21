<?php

namespace App\Http\Controllers\Api;

use App\Actions\SavePendingTask;
use App\Enums\PendingTaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CompleteTaskRequest;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\PendingTaskRequest;
use App\Http\Resources\PendingTaskResource;
use App\Models\PendingTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PendingTaskController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = PendingTask::with('supportTicket');
        foreach (['status', 'support_ticket_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        switch ($request->input('filter')) {
            case 'open':
                $query->where('status', PendingTaskStatus::Pending->value);
                break;
            case 'completed':
                $query->where('status', PendingTaskStatus::Completed->value);
                break;
            case 'overdue':
                $query->where('status', PendingTaskStatus::Pending->value)->where('due_at', '<', now());
                break;
        }

        return PendingTaskResource::collection($query->latest('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(PendingTaskRequest $request, SavePendingTask $save): JsonResponse
    {
        return (new PendingTaskResource($save->execute(null, $request->validated())->refresh()->load('supportTicket')))
            ->response()->setStatusCode(201);
    }

    public function show(PendingTask $pendingTask): PendingTaskResource
    {
        return new PendingTaskResource($pendingTask->load('supportTicket'));
    }

    public function update(PendingTaskRequest $request, PendingTask $pendingTask, SavePendingTask $save): PendingTaskResource
    {
        return new PendingTaskResource($save->execute($pendingTask, $request->validated())->refresh()->load('supportTicket'));
    }

    public function complete(CompleteTaskRequest $request, PendingTask $pendingTask, SavePendingTask $save): PendingTaskResource
    {
        return new PendingTaskResource($save->execute($pendingTask, [
            ...$request->validated(), 'status' => PendingTaskStatus::Completed->value,
        ])->refresh()->load('supportTicket'));
    }
}
