<?php

namespace App\Http\Requests\Api;

use App\Enums\VehicleLine;
use App\Models\Vehicle;
use App\Support\VehiclePlate;
use Illuminate\Validation\Rule;

class VehicleRequest extends ApiRequest
{
    public const DUPLICATE_PLATE = 'Já existe um veículo cadastrado com esta placa.';

    protected function prepareForValidation(): void
    {
        $plate = $this->input('plate');
        if (is_string($plate)) {
            $this->merge(['plate' => VehiclePlate::normalize($plate)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $uniquePlate = Rule::unique(Vehicle::class, 'plate');
        $vehicle = $this->route('vehicle');
        if ($vehicle instanceof Vehicle) {
            $uniquePlate->ignoreModel($vehicle);
        }

        return [
            'brand_id' => ['bail', ...$this->presence(), 'integer:strict', 'exists:vehicle_brands,id'],
            'vehicle_line' => ['bail', ...$this->presence(), Rule::enum(VehicleLine::class)],
            'plate' => ['bail', ...$this->presence(), 'string', VehiclePlate::FORMAT, $uniquePlate],
            'model_description' => ['nullable', 'string', 'max:16000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'brand_id.required' => 'Selecione a marca do veículo.',
            'vehicle_line.required' => 'Informe a linha veicular.',
            'vehicle_line.enum' => 'A linha veicular deve ser light (leve) ou heavy (pesada).',
            'plate.required' => 'A placa é obrigatória.',
            'plate.regex' => 'Informe uma placa no formato tradicional (ABC1234) ou Mercosul (ABC1D23).',
            'plate.unique' => self::DUPLICATE_PLATE,
        ];
    }
}
