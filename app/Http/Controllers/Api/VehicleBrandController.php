<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\VehicleBrandRequest;
use App\Http\Resources\VehicleBrandResource;
use App\Models\VehicleBrand;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class VehicleBrandController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = VehicleBrand::withCount('vehicles');
        if ($request->filled('q')) {
            $query->matchingName($request->string('q')->toString());
        }

        return VehicleBrandResource::collection($query->orderBy('normalized_name')->paginate($request->perPage())->withQueryString());
    }

    public function store(VehicleBrandRequest $request): JsonResponse
    {
        return $this->save($request, new VehicleBrand)->response()->setStatusCode(201);
    }

    public function show(VehicleBrand $vehicleBrand): VehicleBrandResource
    {
        return new VehicleBrandResource($vehicleBrand);
    }

    public function update(VehicleBrandRequest $request, VehicleBrand $vehicleBrand): VehicleBrandResource
    {
        return $this->save($request, $vehicleBrand);
    }

    private function save(VehicleBrandRequest $request, VehicleBrand $brand): VehicleBrandResource
    {
        try {
            $brand->fill($request->validated())->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => VehicleBrandRequest::DUPLICATE_NAME]);
        }

        return $this->show($brand->refresh());
    }
}
