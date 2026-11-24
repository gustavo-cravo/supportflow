<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TicketMetricsRequest;
use App\Http\Resources\SupportTicketResource;
use App\Services\TicketMetrics;
use App\Services\VehicleMetrics;
use App\Support\TicketMetricsFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TicketMetricsController extends Controller
{
    public function summary(TicketMetricsRequest $request, TicketMetrics $metrics): JsonResponse
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());

        return response()->json(['data' => $metrics->summary($filters), 'meta' => ['filters' => $filters->toArray()]]);
    }

    public function systems(TicketMetricsRequest $request, TicketMetrics $metrics): JsonResponse
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());
        $rows = $metrics->systems($filters);

        return response()->json(['data' => $rows, 'meta' => ['filters' => $filters->toArray(), 'total' => array_sum(array_column($rows, 'total'))]]);
    }

    public function categories(TicketMetricsRequest $request, TicketMetrics $metrics): JsonResponse
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());
        $rows = $metrics->categories($filters);

        return response()->json(['data' => $rows, 'meta' => ['filters' => $filters->toArray(), 'total' => array_sum(array_column($rows, 'total'))]]);
    }

    public function subcategories(TicketMetricsRequest $request, TicketMetrics $metrics): JsonResponse
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());
        $rows = $metrics->subcategories($filters);

        return response()->json(['data' => $rows, 'meta' => [
            'filters' => $filters->toArray(), 'total' => array_sum(array_column($rows, 'total')),
            'occurrences' => array_sum(array_column(array_filter($rows, fn (array $row) => $row['problem_subcategory_id'] !== null), 'total')),
        ]]);
    }

    public function monthly(TicketMetricsRequest $request, TicketMetrics $metrics): JsonResponse
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());
        $rows = $metrics->monthly($filters);

        return response()->json(['data' => $rows, 'meta' => ['filters' => $filters->toArray(), 'total' => array_sum(array_column($rows, 'total'))]]);
    }

    public function vehicles(TicketMetricsRequest $request, VehicleMetrics $metrics): JsonResponse
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());

        return response()->json([
            'data' => DB::transaction(fn () => $metrics->build($filters)),
            'meta' => ['filters' => $filters->toArray()],
        ]);
    }

    public function tickets(TicketMetricsRequest $request, TicketMetrics $metrics): AnonymousResourceCollection
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());

        return SupportTicketResource::collection($metrics->tickets($filters, $request->integer('per_page', 15)))
            ->additional(['meta' => ['filters' => $filters->toArray()]]);
    }
}
