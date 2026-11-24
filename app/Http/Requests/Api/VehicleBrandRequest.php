<?php

namespace App\Http\Requests\Api;

use App\Models\VehicleBrand;
use App\Support\ProblemName;
use Closure;

class VehicleBrandRequest extends ApiRequest
{
    public const DUPLICATE_NAME = 'Já existe uma marca de veículo com este nome.';

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => ProblemName::clean($name)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $brand = $this->route('vehicleBrand');

        return [
            'name' => ['bail', ...$this->presence(), 'string', 'max:255', function (string $attribute, string $name, Closure $fail) use ($brand): void {
                $query = VehicleBrand::where('normalized_name', ProblemName::key($name));
                if ($brand instanceof VehicleBrand) {
                    $query->whereKeyNot($brand->id);
                }
                if ($query->exists()) {
                    $fail(self::DUPLICATE_NAME);
                }
            }],
        ];
    }
}
