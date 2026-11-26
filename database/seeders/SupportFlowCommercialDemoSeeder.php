<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class SupportFlowCommercialDemoSeeder extends Seeder
{
    public function run(SupportFlowCommercialDataset $dataset): void
    {
        $result = $dataset->seed();

        $this->command->info($result['created']
            ? 'SFT-COMERCIAL — lote criado com 250 atendimentos.'
            : 'SFT-COMERCIAL — lote existente. Registros e datas preservados.');
        $this->command->line('Aberturas de '.$result['from'].' a '.$result['to'].' (horário de Brasília).');
    }
}
