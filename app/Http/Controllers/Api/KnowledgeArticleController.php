<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\KnowledgeArticleRequest;
use App\Http\Requests\Api\ListRequest;
use App\Http\Resources\KnowledgeArticleResource;
use App\Models\KnowledgeArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class KnowledgeArticleController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = KnowledgeArticle::with('system');
        if ($request->filled('system_id')) {
            $query->where('system_id', $request->integer('system_id'));
        }
        if ($request->filled('q')) {
            $query->where(function ($query) use ($request) {
                $query->whereLike('problem', '%'.$request->string('q').'%')
                    ->orWhereLike('resolution_procedure', '%'.$request->string('q').'%')
                    ->orWhereHas('system', fn ($system) => $system->whereLike('name', '%'.$request->string('q').'%'));
            });
        }

        return KnowledgeArticleResource::collection($query->latest('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(KnowledgeArticleRequest $request): JsonResponse
    {
        return (new KnowledgeArticleResource(KnowledgeArticle::create($request->validated())->refresh()->load('system')))
            ->response()->setStatusCode(201);
    }

    public function show(KnowledgeArticle $knowledgeArticle): KnowledgeArticleResource
    {
        return new KnowledgeArticleResource($knowledgeArticle->load('system'));
    }

    public function update(KnowledgeArticleRequest $request, KnowledgeArticle $knowledgeArticle): KnowledgeArticleResource
    {
        $knowledgeArticle->update($request->validated());

        return new KnowledgeArticleResource($knowledgeArticle->refresh()->load('system'));
    }
}
