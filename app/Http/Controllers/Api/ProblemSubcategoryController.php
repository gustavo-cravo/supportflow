<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveProblemClassification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\ProblemClassificationRequest;
use App\Http\Resources\ProblemClassificationResource;
use App\Models\ProblemSubcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProblemSubcategoryController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = ProblemSubcategory::with(['systems', 'category']);
        if ($request->boolean('selectable')) {
            $systemId = $request->integer('system_id');
            $query->where('is_active', true)->where('problem_category_id', $request->integer('problem_category_id'))
                ->whereHas('category', fn ($query) => $query->where('is_active', true)
                    ->whereHas('systems', fn ($query) => $query->where('systems.id', $systemId)
                        ->where('systems.is_active', true)->where('problem_category_system.is_active', true)))
                ->whereHas('systems', fn ($query) => $query->where('systems.id', $systemId)
                    ->where('systems.is_active', true)->where('problem_subcategory_system.is_active', true));
        }
        if ($request->filled('q')) {
            $query->where(function ($query) use ($request) {
                $query->whereLike('name', '%'.$request->string('q').'%')->orWhereLike('description', '%'.$request->string('q').'%');
            });
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('problem_category_id')) {
            $query->where('problem_category_id', $request->integer('problem_category_id'));
        }
        if ($request->filled('system_id')) {
            $query->whereHas('systems', fn ($query) => $query->where('systems.id', $request->integer('system_id'))->where('problem_subcategory_system.is_active', true));
        }

        return ProblemClassificationResource::collection($query->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function store(ProblemClassificationRequest $request, SaveProblemClassification $save): JsonResponse
    {
        return $this->detail($save->subcategory(null, $request->validated()))->response()->setStatusCode(201);
    }

    public function show(ProblemSubcategory $problemSubcategory): ProblemClassificationResource
    {
        return $this->detail($problemSubcategory);
    }

    public function update(ProblemClassificationRequest $request, ProblemSubcategory $problemSubcategory, SaveProblemClassification $save): ProblemClassificationResource
    {
        return $this->detail($save->subcategory($problemSubcategory, $request->validated()));
    }

    private function detail(ProblemSubcategory $subcategory): ProblemClassificationResource
    {
        return new ProblemClassificationResource($subcategory->refresh()->load(['systems', 'category']));
    }
}
