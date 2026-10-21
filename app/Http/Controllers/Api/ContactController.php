<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContactRequest;
use App\Http\Requests\Api\ListRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = Contact::query();
        if ($request->filled('q')) {
            $query->where(function ($query) use ($request) {
                $query->whereLike('name', '%'.$request->string('q').'%');
                $query->orWhereLike('phone', '%'.$request->string('q').'%');
                $query->orWhereLike('email', '%'.$request->string('q').'%');
                $query->orWhereLike('notes', '%'.$request->string('q').'%');
            });
        }

        return ContactResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(ContactRequest $request): JsonResponse
    {
        return (new ContactResource(Contact::create($request->validated())->refresh()))->response()->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        return new ContactResource($contact);
    }

    public function update(ContactRequest $request, Contact $contact): ContactResource
    {
        $contact->update($request->validated());

        return new ContactResource($contact->refresh());
    }
}
