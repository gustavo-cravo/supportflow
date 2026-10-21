<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\ReminderRequest;
use App\Http\Resources\ReminderResource;
use App\Models\Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ReminderController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = Reminder::with('supportTicket');
        if ($request->filled('support_ticket_id')) {
            $query->where('support_ticket_id', $request->integer('support_ticket_id'));
        }
        $filter = $request->input('filter');
        if ($filter === 'completed') {
            $query->whereNotNull('completed_at');
        } elseif ($filter !== null) {
            $query->whereNull('completed_at');
            switch ($filter) {
                case 'today':
                    $query->where('scheduled_at', '>=', today())->where('scheduled_at', '<', today()->addDay());
                    break;
                case 'overdue':
                    $query->where('scheduled_at', '<', now());
                    break;
                case 'upcoming':
                    $query->where('scheduled_at', '>=', today()->addDay());
                    break;
            }
        }

        return ReminderResource::collection($query->orderBy('scheduled_at')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(ReminderRequest $request): JsonResponse
    {
        return (new ReminderResource(Reminder::create($request->validated())->refresh()->load('supportTicket')))->response()->setStatusCode(201);
    }

    public function show(Reminder $reminder): ReminderResource
    {
        return new ReminderResource($reminder->load('supportTicket'));
    }

    public function update(ReminderRequest $request, Reminder $reminder): ReminderResource
    {
        $reminder->update($request->validated());

        return new ReminderResource($reminder->refresh()->load('supportTicket'));
    }

    public function complete(Reminder $reminder): ReminderResource
    {
        $reminder = DB::transaction(function () use ($reminder): Reminder {
            $reminder = Reminder::query()->whereKey($reminder->getKey())->lockForUpdate()->firstOrFail();
            $reminder->completed_at ??= now();
            $reminder->save();

            return $reminder;
        }, attempts: 3);

        return new ReminderResource($reminder->load('supportTicket'));
    }
}
