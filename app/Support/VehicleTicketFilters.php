<?php

namespace App\Support;

use App\Enums\VehicleLine;
use App\Models\Vehicle;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final readonly class VehicleTicketFilters
{
    public const CONTRADICTION = 'Remova os filtros de placa, marca, modelo e linha para buscar atendimentos sem veículo.';

    public function __construct(
        public ?string $presence,
        public ?string $plate,
        public ?int $brandId,
        public ?string $model,
        public ?string $line,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(array $data): self
    {
        return new self(
            $data['vehicle_presence'] ?? null,
            $data['vehicle_plate'] ?? null,
            isset($data['vehicle_brand_id']) ? (int) $data['vehicle_brand_id'] : null,
            $data['vehicle_model'] ?? null,
            $data['vehicle_line'] ?? null,
        );
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $withVehicle = 'prohibited_if:vehicle_presence,without';

        return [
            'vehicle_presence' => ['nullable', Rule::in(['with', 'without'])],
            'vehicle_plate' => [$withVehicle, 'nullable', 'string', 'regex:/\A[A-Z0-9]{1,7}\z/'],
            'vehicle_brand_id' => [$withVehicle, 'nullable', 'integer', 'min:1'],
            'vehicle_model' => [$withVehicle, 'nullable', 'string', 'max:255'],
            'vehicle_line' => [$withVehicle, 'nullable', Rule::enum(VehicleLine::class)],
        ];
    }

    public static function normalizePlate(mixed $plate): mixed
    {
        if (is_string($plate)) {
            $normalized = VehiclePlate::normalize($plate);

            return $normalized === '' ? $plate : $normalized;
        }

        return $plate;
    }

    public function apply(Builder $tickets): void
    {
        if ($this->presence === 'without') {
            $tickets->whereNull('support_tickets.vehicle_id');
        } elseif ($this->presence === 'with') {
            $tickets->whereNotNull('support_tickets.vehicle_id');
        }

        if ($this->plate === null && $this->model === null && $this->brandId === null && $this->line === null) {
            return;
        }

        $vehicle = Vehicle::query()->selectRaw('1')->whereColumn('vehicles.id', 'support_tickets.vehicle_id');
        if ($this->plate !== null) {
            $vehicle->matchingPlate($this->plate);
        }
        if ($this->model !== null) {
            $vehicle->matchingModel($this->model);
        }
        if ($this->brandId !== null) {
            $vehicle->where('brand_id', $this->brandId);
        }
        if ($this->line !== null) {
            $vehicle->where('vehicle_line', $this->line);
        }
        $tickets->whereExists($vehicle->toBase());
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return array_filter([
            'vehicle_presence' => $this->presence,
            'vehicle_plate' => $this->plate,
            'vehicle_brand_id' => $this->brandId,
            'vehicle_model' => $this->model,
            'vehicle_line' => $this->line,
        ], static fn ($value) => $value !== null);
    }
}
