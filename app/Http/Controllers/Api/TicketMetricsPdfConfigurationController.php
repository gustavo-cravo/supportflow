<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TicketMetricsPdfConfigurationRequest;
use App\Support\TicketMetricsPdfCatalog;
use Illuminate\Http\JsonResponse;

class TicketMetricsPdfConfigurationController extends Controller
{
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => TicketMetricsPdfCatalog::options()]);
    }

    public function validateConfiguration(TicketMetricsPdfConfigurationRequest $request): JsonResponse
    {
        return response()->json(['data' => $request->configuration()->toArray()], 200, ['Cache-Control' => 'private, no-store']);
    }
}
