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
use App\Http\Resources\VehicleResource;
use App\Models\SupportTicket;
use App\Models\Vehicle;
use App\Support\VehicleTicketFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class SupportTicketController extends Controller
{
    private const LIST_RELATIONS = ['system', 'contact', 'customer', 'vehicle.brand', ...SupportTicket::CLASSIFICATION_RELATIONS];

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = SupportTicket::with(self::LIST_RELATIONS);
        $this->filterTickets($query, $request);
        VehicleTicketFilters::fromValidated($request->validated())->apply($query->getQuery());

        return SupportTicketResource::collection($query->latest('id')->paginate($request->perPage())->withQueryString());
    }

    public function vehicleHistory(ListRequest $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        $summary = $vehicle->supportTickets()->toBase()
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS closed', [TicketStatus::Closed->value])
            ->first();
        $total = (int) $summary?->total;
        $closed = (int) $summary?->closed;
        $query = SupportTicket::with(self::LIST_RELATIONS)->where('vehicle_id', $vehicle->getKey());
        $this->filterTickets($query, $request);
        $records = $query->latest('created_at')->latest('id')->paginate($request->perPage())->withQueryString();

        return SupportTicketResource::collection($records)->additional(['meta' => [
            'vehicle' => new VehicleResource($vehicle->load('brand')),
            'counts' => ['total' => $total, 'not_closed' => $total - $closed, 'closed' => $closed],
        ]]);
    }

    /** @param Builder<SupportTicket> $query */
    private function filterTickets(Builder $query, ListRequest $request): void
    {
        foreach (['system_id', 'contact_id', 'customer_id', 'problem_category_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('problem_subcategory_id')) {
            $query->whereRelation('problemSubcategories', 'problem_subcategories.id', $request->integer('problem_subcategory_id'));
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
        $query = SupportTicket::with(self::LIST_RELATIONS)
            ->where(function ($query) use ($supportTicket) {
                $query->where('previous_ticket_id', $supportTicket->getKey())
                    ->orWhere('id', $supportTicket->previous_ticket_id);
            });

        return SupportTicketResource::collection($query->latest('id')->paginate($request->perPage())->withQueryString());
    }

    private function detail(SupportTicket $ticket): SupportTicketResource
    {
        return new SupportTicketResource($ticket->refresh()->load([
            'system', 'contact', 'customer', 'vehicle.brand', 'previousTicket.vehicle.brand', 'followUpTickets.vehicle.brand', 'pendingTasks', 'reminders',
            ...SupportTicket::CLASSIFICATION_RELATIONS,
        ]));
    }
}
