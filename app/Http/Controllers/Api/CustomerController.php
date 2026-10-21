<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CustomerRequest;
use App\Http\Requests\Api\ListRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = Customer::query();
        if ($request->filled('q')) {
            $query->where(function ($query) use ($request) {
                $query->whereLike('name', '%'.$request->string('q').'%');
                $query->orWhereLike('phone', '%'.$request->string('q').'%');
                $query->orWhereLike('email', '%'.$request->string('q').'%');
                $query->orWhereLike('notes', '%'.$request->string('q').'%');
            });
        }

        return CustomerResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        return (new CustomerResource(Customer::create($request->validated())->refresh()))->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer->refresh());
    }
}
