<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveSupportTicket;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CloseTicketRequest;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\SupportTicketRequest;
use App\Http\Requests\Api\TicketStatusRequest;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class SupportTicketController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = SupportTicket::with(['system', 'contact', 'customer']);
        foreach (['system_id', 'contact_id', 'customer_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('created_from')) {
            $query->where('created_at', '>=', $request->string('created_from').' 00:00:00');
        }
        if ($request->filled('created_to')) {
            $query->where('created_at', '<', Carbon::parse($request->string('created_to')->toString())->addDay()->startOfDay());
        }
        if ($request->filled('q')) {
            $query->where(function ($query) use ($request) {
                $query->whereLike('title', '%'.$request->string('q').'%')
                    ->orWhereLike('description', '%'.$request->string('q').'%');
            });
        }

        return SupportTicketResource::collection($query->latest('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(SupportTicketRequest $request, SaveSupportTicket $save): JsonResponse
    {
        return $this->detail($save->execute(null, $request->validated()))->response()->setStatusCode(201);
    }

    public function show(SupportTicket $supportTicket): SupportTicketResource
    {
        return $this->detail($supportTicket);
    }

    public function update(SupportTicketRequest $request, SupportTicket $supportTicket, SaveSupportTicket $save): SupportTicketResource
    {
        return $this->detail($save->execute($supportTicket, $request->validated()));
    }

    public function status(TicketStatusRequest $request, SupportTicket $supportTicket, SaveSupportTicket $save): SupportTicketResource
    {
        return $this->detail($save->execute($supportTicket, $request->validated()));
    }

    public function close(CloseTicketRequest $request, SupportTicket $supportTicket, SaveSupportTicket $save): SupportTicketResource
    {
        return $this->detail($save->execute($supportTicket, [...$request->validated(), 'status' => TicketStatus::Closed->value]));
    }

    public function related(ListRequest $request, SupportTicket $supportTicket): AnonymousResourceCollection
    {
        $query = SupportTicket::with(['system', 'contact', 'customer'])
            ->where(function ($query) use ($supportTicket) {
                $query->where('previous_ticket_id', $supportTicket->getKey())
                    ->orWhere('id', $supportTicket->previous_ticket_id);
            });

        return SupportTicketResource::collection($query->latest('id')->paginate($request->perPage())->withQueryString());
    }

    private function detail(SupportTicket $ticket): SupportTicketResource
    {
        return new SupportTicketResource($ticket->refresh()->load([
            'system', 'contact', 'customer', 'previousTicket', 'followUpTickets', 'pendingTasks', 'reminders',
        ]));
    }
}
