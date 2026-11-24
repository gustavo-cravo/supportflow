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

final readonly class VehicleHistoryFixture
{
    public VehicleBrand $brand;

    public VehicleBrand $otherBrand;

    public Vehicle $car;

    public Vehicle $truck;

    public System $system;

    public Customer $customer;

    public ProblemCategory $category;

    public ProblemSubcategory $subcategory;

    public SupportTicket $recent;

    public SupportTicket $earlier;

    public SupportTicket $sameDay;

    public SupportTicket $heavy;

    public SupportTicket $unlinked;

    public string $history;

    public function __construct()
    {
        $this->brand = VehicleBrand::create(['name' => 'Volkswagen']);
        $this->otherBrand = VehicleBrand::create(['name' => 'Ford']);
        $this->car = Vehicle::factory()->for($this->brand, 'brand')->create([
            'plate' => 'HFS4456', 'vehicle_line' => 'light', 'model_description' => 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007',
        ]);
        $this->truck = Vehicle::factory()->for($this->otherBrand, 'brand')->create([
            'plate' => 'ABC1D23', 'vehicle_line' => 'heavy', 'model_description' => null,
        ]);
        $this->system = System::create(['name' => 'Sistema A']);
        $otherSystem = System::create(['name' => 'Sistema B']);
        $contact = Contact::create(['name' => 'Ana']);
        $this->customer = Customer::create(['name' => 'Oficina']);
        $this->category = ProblemCategory::create(['name' => 'Identificação']);
        $first = ProblemSubcategory::create(['name' => 'Placa', 'problem_category_id' => $this->category->id]);
        $this->subcategory = ProblemSubcategory::create(['name' => 'Modelo', 'problem_category_id' => $this->category->id]);
        $base = ['system_id' => $this->system->id, 'contact_id' => $contact->id,
            'title' => 'Consulta de placa', 'description' => 'O aplicativo retornava outro veículo.'];
        $create = fn (array $attributes) => SupportTicket::unguarded(fn () => SupportTicket::create([...$base, ...$attributes]));
        $this->recent = $create(['vehicle_id' => $this->car->id, 'customer_id' => $this->customer->id,
            'status' => 'closed', 'problem_category_id' => $this->category->id, 'problem_subcategory_id' => $first->id,
            'created_at' => '2026-11-20 23:59:59', 'closed_at' => '2026-11-21 10:00:00', 'resolution' => 'Cadastro conferido.']);
        $this->recent->problemSubcategories()->attach([$first->id, $this->subcategory->id], ['problem_category_id' => $this->category->id]);
        $this->earlier = $create(['vehicle_id' => $this->car->id, 'system_id' => $otherSystem->id,
            'status' => 'resolved', 'created_at' => '2026-11-17 10:00:00']);
        $this->sameDay = $create(['vehicle_id' => $this->car->id, 'status' => 'waiting', 'created_at' => '2026-11-17 10:00:00']);
        $this->heavy = $create(['vehicle_id' => $this->truck->id, 'status' => 'in_progress', 'created_at' => '2026-11-22 10:00:00']);
        $this->unlinked = $create(['title' => 'Chamado sem veículo', 'created_at' => '2026-11-23 10:00:00']);
        $this->history = '/api/vehicles/'.$this->car->id.'/support-tickets';
    }
}
