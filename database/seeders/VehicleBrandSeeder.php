<?php

namespace Database\Seeders;

use App\Models\VehicleBrand;
use App\Support\ProblemName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class VehicleBrandSeeder extends Seeder
{
    public function run(VehicleDemoDataset $demo): void
    {
        $catalog = $this->catalog();

        DB::transaction(function () use ($catalog, $demo): void {
            $ids = [];
            foreach ($catalog as $key => $name) {
                $brand = VehicleBrand::query()->lockForUpdate()->firstOrCreate(
                    ['normalized_name' => $key],
                    ['name' => $name],
                );
                if ($brand->name !== $name) {
                    if (Str::lower($brand->name) !== Str::lower($name)) {
                        throw new RuntimeException(sprintf(
                            'Conflito no catálogo veicular: a marca "%s" (ID %d) tem a mesma chave de "%s", mas difere além da capitalização.',
                            $brand->name, $brand->id, $name,
                        ));
                    }
                    $brand->timestamps = false;
                    $brand->update(['name' => $name]);
                }
                $ids[] = $brand->id;
            }
            $demo->releaseVehicleBrands($ids);
        }, attempts: 3);
    }

    /** @return array<string, string> */
    private function catalog(): array
    {
        $names = file(__DIR__.'/data/vehicle-brands.txt', FILE_IGNORE_NEW_LINES);
        if ($names === false || count($names) !== 125) {
            throw new RuntimeException('O catálogo oficial deve conter exatamente 125 marcas.');
        }

        $catalog = [];
        foreach ($names as $name) {
            $key = ProblemName::key($name);
            if ($name === '' || $name !== ProblemName::clean($name) || $name !== Str::upper($name) || mb_strlen($name) > 255) {
                throw new RuntimeException('Nome incompatível com o cadastro oficial: '.$name);
            }
            if (isset($catalog[$key])) {
                throw new RuntimeException(sprintf('Marcas oficiais com a mesma chave de unicidade: "%s" e "%s".', $catalog[$key], $name));
            }
            $catalog[$key] = $name;
        }

        return $catalog;
    }
}
