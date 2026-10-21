<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\SystemRequest;
use App\Http\Resources\SystemResource;
use App\Models\System;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SystemController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = System::query();
        if ($request->filled('q')) {
            $query->where(function ($query) use ($request) {
                $query->whereLike('name', '%'.$request->string('q').'%');
            });
        }
        if ($request->filled('name')) {
            $query->whereLike('name', '%'.$request->string('name').'%');
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return SystemResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(SystemRequest $request): JsonResponse
    {
        return (new SystemResource(System::create($request->validated())->refresh()))->response()->setStatusCode(201);
    }

    public function show(System $system): SystemResource
    {
        return new SystemResource($system);
    }

    public function update(SystemRequest $request, System $system): SystemResource
    {
        $system->update($request->validated());

        return new SystemResource($system->refresh());
    }
}
