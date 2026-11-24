<?php

use Database\Seeders\VehicleDemoDataset;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:vehicles:clean', function (VehicleDemoDataset $dataset): void {
    $result = $dataset->clean();
    if ($result['removed'] === [] && $result['retained'] === []) {
        $this->info('Nenhum registro veicular demonstrativo gerenciado neste banco.');

        return;
    }
    $this->info(array_sum($result['removed']).' registros demonstrativos removidos.');
    foreach ($result['retained'] as $table => $count) {
        $this->warn($count.' registro(s) preservado(s) em '.$table.' por alteração ou uso compartilhado.');
    }
})->purpose('Remove somente os dados gerenciados pela demonstração veicular');
