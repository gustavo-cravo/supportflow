<?php

namespace App\Http\Requests\Api;

use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Support\ProblemName;
use Closure;
use Illuminate\Validation\Rule;

class ProblemClassificationRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => ProblemName::clean($this->input('name'))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $subcategory = $this->routeIs('problem-subcategories.*');
        $record = $this->route($subcategory ? 'problemSubcategory' : 'problemCategory');
        $rules = [
            'name' => ['bail', ...$this->presence(), 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($subcategory, $record): void {
                $query = $subcategory ? ProblemSubcategory::query() : ProblemCategory::query();
                $query->where('normalized_name', ProblemName::key($value));
                if ($record instanceof ProblemCategory || $record instanceof ProblemSubcategory) {
                    $query->whereKeyNot($record->id);
                }
                if ($subcategory) {
                    $categoryId = $record instanceof ProblemSubcategory ? $record->problem_category_id : $this->input('problem_category_id');
                    if (! is_int($categoryId) && ! (is_string($categoryId) && ctype_digit($categoryId))) {
                        return;
                    }
                    $query->where('problem_category_id', $categoryId);
                }
                if ($query->exists()) {
                    $fail($subcategory ? 'Já existe uma subcategoria com esse nome nesta categoria.' : 'Já existe uma categoria com esse nome.');
                }
            }],
            'description' => ['nullable', 'string', 'max:16000'],
            'is_active' => ['sometimes', 'boolean'],
            'system_ids' => [...$this->presence(), 'array', 'list', 'min:1', 'max:1000'],
            'system_ids.*' => ['bail', 'required', 'integer', 'distinct', 'min:1'],
        ];
        if ($subcategory) {
            $rules['problem_category_id'] = [...$this->presence(), 'integer', Rule::exists('problem_categories', 'id')];
            if ($record instanceof ProblemSubcategory) {
                $rules['problem_category_id'][] = Rule::in([$record->problem_category_id]);
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'system_ids.required' => 'Selecione pelo menos um sistema.',
            'system_ids.min' => 'Selecione pelo menos um sistema.',
            'system_ids.max' => 'Selecione no máximo 1000 sistemas por registro.',
            'system_ids.array' => 'Informe uma lista de sistemas.',
            'system_ids.list' => 'Informe uma lista de sistemas.',
            'system_ids.*.distinct' => 'Não repita sistemas na seleção.',
            'problem_category_id.in' => 'A categoria de uma subcategoria não pode ser alterada.',
        ];
    }
}
