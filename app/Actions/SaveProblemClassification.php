<?php

namespace App\Actions;

use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\System;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProblemClassification
{
    /** @param array<string, mixed> $data */
    public function category(?ProblemCategory $category, array $data): ProblemCategory
    {
        try {
            return DB::transaction(function () use ($category, $data): ProblemCategory {
                $category = $category === null ? new ProblemCategory : ProblemCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
                if (isset($data['system_ids'])) {
                    $this->validateSystems($data['system_ids'], $category->exists ? $category->systems() : null);
                }
                $category->fill(collect($data)->except('system_ids')->all())->save();
                if (isset($data['system_ids'])) {
                    $this->syncSystems($category->systems(), $data['system_ids']);
                    // Availability changes never remove association rows or subcategories.
                    DB::table('problem_subcategory_system')->where('problem_category_id', $category->id)
                        ->whereNotIn('system_id', $data['system_ids'])->where('is_active', true)
                        ->update(['is_active' => false, 'updated_at' => now()]);
                }

                return $category;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe uma categoria com esse nome.']);
        }
    }

    /** @param array<string, mixed> $data */
    public function subcategory(?ProblemSubcategory $subcategory, array $data): ProblemSubcategory
    {
        try {
            return DB::transaction(function () use ($subcategory, $data): ProblemSubcategory {
                // All changes in one category share this lock, including removal of systems.
                $categoryId = $subcategory !== null ? $subcategory->problem_category_id : (int) $data['problem_category_id'];
                $category = ProblemCategory::query()->whereKey($categoryId)->lockForUpdate()->firstOrFail();
                $subcategory = $subcategory === null ? new ProblemSubcategory : ProblemSubcategory::query()->whereKey($subcategory->id)->lockForUpdate()->firstOrFail();
                if (isset($data['system_ids'])) {
                    $allowed = $category->systems()->wherePivot('is_active', true)->pluck('systems.id')->all();
                    if (array_diff($data['system_ids'], $allowed)) {
                        throw ValidationException::withMessages(['system_ids' => 'Selecione apenas sistemas associados à categoria principal.']);
                    }
                    $this->validateSystems($data['system_ids'], $subcategory->exists ? $subcategory->systems() : null);
                }
                unset($data['problem_category_id']);
                $subcategory->fill(collect($data)->except('system_ids')->all());
                $subcategory->problem_category_id = $category->id;
                $subcategory->save();
                if (isset($data['system_ids'])) {
                    $this->syncSystems($subcategory->systems(), $data['system_ids'], ['problem_category_id' => $category->id]);
                }

                return $subcategory;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe uma subcategoria com esse nome nesta categoria.']);
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  BelongsToMany<System, ProblemCategory>|BelongsToMany<System, ProblemSubcategory>|null  $relation
     */
    private function validateSystems(array $ids, ?BelongsToMany $relation): void
    {
        $retained = $relation?->wherePivot('is_active', true)->pluck('systems.id')->all() ?? [];
        $systems = System::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($systems->count() !== count($ids)) {
            throw ValidationException::withMessages(['system_ids' => 'Um dos sistemas selecionados não existe ou está repetido.']);
        }
        foreach ($systems as $system) {
            if (! $system->is_active && ! in_array($system->id, $retained, true)) {
                throw ValidationException::withMessages(['system_ids' => 'Novas associações exigem sistemas ativos. Os vínculos existentes com sistemas inativos podem ser mantidos.']);
            }
        }
    }

    /**
     * @param  BelongsToMany<System, ProblemCategory>|BelongsToMany<System, ProblemSubcategory>  $relation
     * @param  list<int>  $ids
     * @param  array<string, int>  $extra
     */
    private function syncSystems(BelongsToMany $relation, array $ids, array $extra = []): void
    {
        $relation->newPivotQuery()->whereNotIn('system_id', $ids)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
        $relation->syncWithoutDetaching(array_fill_keys($ids, ['is_active' => true, ...$extra]));
    }
}
