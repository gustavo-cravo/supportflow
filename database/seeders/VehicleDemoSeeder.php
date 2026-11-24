<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class VehicleDemoSeeder extends Seeder
{
    public function run(VehicleDemoDataset $dataset): void
    {
        $result = $dataset->seed();
        $this->command->info($result['created'] ? 'Base veicular demonstrativa criada.' : 'Base veicular demonstrativa já existente, sem alterações.');
        $this->command->line(VehicleDemoDataset::SYSTEM_NAME.' · ID '.$result['system_id']);
        $this->command->line('Aberturas de '.$result['from'].' a '.$result['to'].' (horário de Brasília).');
    }
}
