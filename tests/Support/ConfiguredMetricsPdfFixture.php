<?php

namespace Tests\Support;

use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\Vehicle;
use App\Models\VehicleBrand;

final class ConfiguredMetricsPdfFixture
{
    /** @param list<string>|array<string, string> $indicators */
    public static function section(array $indicators): array
    {
        $selected = [];
        foreach ($indicators as $key => $value) {
            $selected[is_int($key) ? $value : $key] = ['enabled' => true, 'format' => is_int($key) ? 'automatic' : $value];
        }

        return ['enabled' => true, 'indicators' => $selected];
    }

    /** @param list<array{by: string, format: string}> $additional */
    public static function groups(array $complements, string $by, string $format = 'automatic', array $additional = []): array
    {
        return [
            'enabled' => true,
            'indicators' => array_fill_keys($complements, ['enabled' => true]),
            'grouping' => ['primary' => ['by' => $by, 'format' => $format], 'additional' => $additional],
        ];
    }

    public static function addVolume(VehicleMetricsFixture $fixture, int $vehicles = 47): void
    {
        $brands = [];
        $categories = [];
        $problems = [];
        for ($i = 1; $i <= $vehicles; $i++) {
            $brand = $i % 16;
            $category = $i % 6;
            $brands[$brand] ??= VehicleBrand::create(['name' => 'Marca de demonstração '.($brand + 1)]);
            $categories[$category] ??= ProblemCategory::create(['name' => 'Grupo de diagnóstico '.($category + 1)]);
            $problems[$category][] = ProblemSubcategory::create(['problem_category_id' => $categories[$category]->id,
                'name' => sprintf('Problema %02d - Validação da compatibilidade e revisão de componentes associados', $i)]);
            $last = array_key_last($problems[$category]);
            $label = sprintf('Família de demonstração %02d - Série especial, versão com cabine estendida e motorização flex', $i);
            if ($i === $vehicles) {
                $label .= ' '.str_repeat('Configuração com transmissão automática, acabamento externo especial e variantes homologadas; ', 46).'Descrição integral confirmada';
            }
            $vehicle = Vehicle::factory()->for($brands[$brand], 'brand')->create([
                'vehicle_line' => $i % 2 === 0 ? 'heavy' : 'light',
                'model_description' => $label,
                'plate' => sprintf('ZQX%04d', $i),
            ]);
            $ticket = $fixture->firstTicket->replicate()->forceFill([
                'vehicle_id' => $vehicle->id, 'problem_category_id' => $categories[$category]->id,
                'created_at' => '2026-11-20 09:10:00',
            ]);
            $ticket->save();
            $ticket->problemSubcategories()->attach(
                $last === 0 ? [$problems[$category][$last]->id] : [$problems[$category][$last]->id, $problems[$category][$last - 1]->id],
                ['problem_category_id' => $categories[$category]->id],
            );
        }
    }
}
