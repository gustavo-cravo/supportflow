<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveProblemClassification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\ProblemClassificationRequest;
use App\Http\Resources\ProblemClassificationResource;
use App\Models\ProblemCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProblemCategoryController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = ProblemCategory::with('systems')->withCount('subcategories');
        if ($request->boolean('selectable')) {
            $query->where('is_active', true)->whereHas('systems', fn ($query) => $query
                ->where('systems.id', $request->integer('system_id'))->where('systems.is_active', true)
                ->where('problem_category_system.is_active', true));
        }
        if ($request->filled('q')) {
            $query->where(function ($query) use ($request) {
                $query->whereLike('name', '%'.$request->string('q').'%')->orWhereLike('description', '%'.$request->string('q').'%');
            });
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('system_id')) {
            $query->whereHas('systems', fn ($query) => $query->where('systems.id', $request->integer('system_id'))->where('problem_category_system.is_active', true));
        }

        return ProblemClassificationResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(ProblemClassificationRequest $request, SaveProblemClassification $save): JsonResponse
    {
        return $this->detail($save->category(null, $request->validated()))->response()->setStatusCode(201);
    }

    public function show(ProblemCategory $problemCategory): ProblemClassificationResource
    {
        return $this->detail($problemCategory);
    }

    public function update(ProblemClassificationRequest $request, ProblemCategory $problemCategory, SaveProblemClassification $save): ProblemClassificationResource
    {
        return $this->detail($save->category($problemCategory, $request->validated()));
    }

    private function detail(ProblemCategory $category): ProblemClassificationResource
    {
        return new ProblemClassificationResource($category->refresh()->load('systems')->loadCount('subcategories'));
    }
}
