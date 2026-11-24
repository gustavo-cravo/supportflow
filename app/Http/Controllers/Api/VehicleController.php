<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\VehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Support\VehiclePlate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = Vehicle::with('brand')->withCount('supportTickets');
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->integer('brand_id'));
        }
        if ($request->filled('vehicle_line')) {
            $query->where('vehicle_line', $request->string('vehicle_line')->toString());
        }
        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $plate = VehiclePlate::normalize($term);
            $query->where(function (Builder $query) use ($plate, $term) {
                $query->matchingModel($term)
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->matchingName($term));
                if (ctype_alnum($plate)) {
                    $query->orWhere(fn (Builder $vehicle) => $vehicle->matchingPlate($plate));
                }
            });
        }

        $counts = Vehicle::query()->toBase()->selectRaw('vehicle_line, COUNT(*) as total')
            ->groupBy('vehicle_line')->pluck('total', 'vehicle_line');

        return VehicleResource::collection($query->orderBy('plate')->paginate($request->perPage())->withQueryString())
            ->additional(['meta' => ['counts' => [
                'total' => (int) $counts->sum(),
                'light' => (int) $counts->get('light', 0),
                'heavy' => (int) $counts->get('heavy', 0),
            ]]]);
    }

    public function store(VehicleRequest $request): JsonResponse
    {
        return $this->save($request, new Vehicle)->response()->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource($vehicle->load('brand')->loadCount('supportTickets'));
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        return $this->save($request, $vehicle);
    }

    private function save(VehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        try {
            $vehicle->fill($request->validated())->save();
        } catch (UniqueConstraintViolationException) {
            // The unique index also resolves writes that race past request validation.
            throw ValidationException::withMessages(['plate' => VehicleRequest::DUPLICATE_PLATE]);
        }

        return $this->show($vehicle->refresh());
    }
}
