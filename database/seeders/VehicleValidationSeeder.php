<?php

namespace Database\Seeders;

use App\Enums\VehicleLine;
use App\Models\Contact;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VehicleValidationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite' || DB::getDatabaseName() !== ':memory:') {
            throw new RuntimeException('VehicleValidationSeeder exige o ambiente de testes com SQLite em memória.');
        }

        DB::transaction(function () {
            $description = 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007';
            $brand = VehicleBrand::factory()->create(['name' => 'Marca Exemplo']);
            $other = VehicleBrand::factory()->create(['name' => 'Outra Marca']);
            $traditional = Vehicle::factory()->for($brand, 'brand')->create(['plate' => 'hfs-4456', 'model_description' => $description]);
            Vehicle::factory()->for($other, 'brand')->mercosul()->create(['plate' => 'abc-1d23', 'model_description' => $description]);
            Vehicle::factory()->for($brand, 'brand')->create(['plate' => 'xyz-1234', 'model_description' => null, 'vehicle_line' => VehicleLine::Heavy]);

            $system = System::create(['name' => 'Identificação veicular fictícia']);
            $contact = Contact::create(['name' => 'Contato fictício']);
            $ticket = ['contact_id' => $contact->id, 'description' => 'Atendimento fictício para validação.'];
            $system->supportTickets()->create([...$ticket, 'title' => 'Consulta pela placa', 'vehicle_id' => $traditional->id]);
            $system->supportTickets()->create([...$ticket, 'title' => 'Revisão do modelo', 'vehicle_id' => $traditional->id]);
            $system->supportTickets()->create([...$ticket, 'title' => 'Atendimento sem veículo']);
        });
    }
}
