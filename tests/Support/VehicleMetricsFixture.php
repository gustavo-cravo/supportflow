<?php

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;

final readonly class VehicleMetricsFixture
{
    public VehicleBrand $alfa;

    public VehicleBrand $beta;

    public System $system;

    public ProblemCategory $category;

    public Vehicle $car;

    public Vehicle $missing;

    public ProblemSubcategory $first;

    public ProblemSubcategory $second;

    public SupportTicket $firstTicket;

    public SupportTicket $secondTicket;

    public function __construct()
    {
        $this->alfa = VehicleBrand::create(['name' => 'Marca Alfa']);
        $this->beta = VehicleBrand::create(['name' => 'Marca Beta']);
        $this->system = System::create(['name' => 'Sistema A']);
        $otherSystem = System::create(['name' => 'Sistema B']);
        $this->category = ProblemCategory::create(['name' => 'Identificação']);
        $this->car = Vehicle::factory()->for($this->alfa, 'brand')->create(['vehicle_line' => 'light', 'model_description' => 'Série 1.6 LX 2007', 'plate' => 'HFS4456']);
        $variant = Vehicle::factory()->for($this->alfa, 'brand')->create(['vehicle_line' => 'heavy', 'model_description' => "sÉrie   1.6\tLX 2007"]);
        $other = Vehicle::factory()->for($this->beta, 'brand')->create(['vehicle_line' => 'heavy', 'model_description' => 'Série 1.6 LX 2007']);
        $this->missing = Vehicle::factory()->for($this->alfa, 'brand')->create(['vehicle_line' => 'light', 'model_description' => null]);
        $version = Vehicle::factory()->for($this->alfa, 'brand')->create(['vehicle_line' => 'light', 'model_description' => 'Série 1.6 LX 2008']);
        Vehicle::factory()->for($this->beta, 'brand')->create(['vehicle_line' => 'heavy', 'model_description' => 'Nunca atendido']);
        $this->first = ProblemSubcategory::create(['name' => 'Modelo divergente', 'problem_category_id' => $this->category->id]);
        $this->second = ProblemSubcategory::create(['name' => 'Ano divergente', 'problem_category_id' => $this->category->id]);
        $base = [
            'system_id' => $this->system->id, 'contact_id' => Contact::create(['name' => 'CONTATO_PRIVADO_987'])->id,
            'customer_id' => Customer::create(['name' => 'CLIENTE_PRIVADO_987'])->id, 'status' => 'open',
            'title' => 'TITULO_PRIVADO_987', 'description' => 'DESCRICAO_PRIVADA_987', 'resolution' => 'SOLUCAO_PRIVADA_987',
            'created_at' => '2026-11-20 09:00:00',
        ];
        $request = fn (array $fields) => SupportTicket::unguarded(fn () => SupportTicket::create([...$base, ...$fields]));
        $this->firstTicket = $request(['vehicle_id' => $this->car->id, 'problem_category_id' => $this->category->id]);
        $this->firstTicket->problemSubcategories()->attach([$this->first->id, $this->second->id], ['problem_category_id' => $this->category->id]);
        $this->secondTicket = $request(['vehicle_id' => $this->car->id, 'problem_category_id' => $this->category->id, 'status' => 'closed', 'created_at' => '2026-11-20 23:59:59']);
        $this->secondTicket->problemSubcategories()->attach($this->first->id, ['problem_category_id' => $this->category->id]);
        $request(['vehicle_id' => $variant->id, 'problem_category_id' => $this->category->id, 'system_id' => $otherSystem->id]);
        $request(['vehicle_id' => $other->id, 'problem_category_id' => $this->category->id]);
        $request(['vehicle_id' => $this->missing->id]);
        $request(['vehicle_id' => $version->id, 'status' => 'closed', 'created_at' => '2026-11-21 00:00:00']);
        $request([]);
    }
}
