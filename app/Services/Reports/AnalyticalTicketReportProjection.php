<?php

namespace App\Services\Reports;

use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Support\AnalyticalTicketReportCatalog as Catalog;
use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\VehicleModelGroup;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-import-type Field from Catalog
 *
 * @phpstan-type PublishedField array{label: string, type: string, width: string, value: string|list<string>|null, absence: ?string}
 * @phpstan-type PublishedSection array{label: string, fields: array<string, PublishedField>, absence: ?string}
 * @phpstan-type PublishedTicket array{sections: array<string, PublishedSection>}
 */
final class AnalyticalTicketReportProjection
{
    /**
     * @param  Builder<SupportTicket>  $query
     * @param  list<string>  $fields
     * @return Builder<SupportTicket>
     */
    public function select(Builder $query, array $fields, bool $modelGrouping = false): Builder
    {
        $columns = ['support_tickets.id'];
        $relations = [];
        $vehicleColumns = [];
        foreach (Catalog::sections() as $section) {
            foreach (array_intersect_key($section['fields'], array_fill_keys($fields, true)) as $definition) {
                $columns[] = 'support_tickets.'.$definition['column'];
                if (isset($definition['with'])) {
                    $relations[] = $definition['with'];
                }
                if (isset($definition['vehicle_column'])) {
                    $vehicleColumns[] = $definition['vehicle_column'];
                }
            }
        }
        if ($vehicleColumns !== []) {
            array_unshift($relations, 'vehicle:id,'.implode(',', $vehicleColumns));
        }

        $query->select(array_values(array_unique($columns)))->with($relations);
        if ($modelGrouping) {
            $vehicle = $query->getConnection()->table('vehicles')->whereColumn('vehicles.id', 'support_tickets.vehicle_id');
            $query->addSelect(['analytical_model_key' => $vehicle->selectRaw(VehicleModelGroup::expression($vehicle))]);
        }

        return $query;
    }

    /** @return PublishedTicket */
    public function ticket(SupportTicket $ticket, AnalyticalTicketReportConfiguration $configuration): array
    {
        $sections = [];
        foreach (Catalog::sections() as $id => $section) {
            $selected = $configuration->sections[$id]['fields'] ?? [];
            if ($selected === []) {
                continue;
            }
            $mode = $configuration->emptyFields;
            if ($id === Catalog::VEHICLE && $mode === 'smart' && $ticket->vehicle === null) {
                $sections[$id] = ['label' => $section['label'], 'fields' => [], 'absence' => Catalog::NO_VEHICLE];

                continue;
            }
            $fields = [];
            foreach ($selected as $key) {
                $definition = $section['fields'][$key];
                $value = $this->value($ticket, $key, $definition);
                $absence = $value === null ? match ($mode) {
                    'show' => $definition['empty'],
                    'smart' => $definition['smart'] ?? null,
                    default => null,
                } : null;
                if ($value !== null || $absence !== null) {
                    $fields[$key] = [
                        'label' => $definition['label'], 'type' => $definition['type'],
                        'width' => Catalog::width($definition['type']), 'value' => $value, 'absence' => $absence,
                    ];
                }
            }
            if ($fields !== []) {
                $sections[$id] = ['label' => $section['label'], 'fields' => $fields, 'absence' => null];
            }
        }

        return ['sections' => $sections];
    }

    /** @return array{string, ?string} */
    public function group(SupportTicket $ticket, string $grouping): array
    {
        return match ($grouping) {
            'system' => ['system:'.$ticket->system_id, $ticket->system->name ?? 'Sistema não informado'],
            'category' => ['category:'.($ticket->problem_category_id ?? 'none'), $ticket->problemCategory->name ?? 'Sem classificação'],
            'brand', 'model' => $this->vehicleGroup($ticket, $grouping),
            default => ['all', null],
        };
    }

    /** @return array{string, string} */
    private function vehicleGroup(SupportTicket $ticket, string $grouping): array
    {
        $vehicle = $ticket->vehicle;
        if ($vehicle === null) {
            return ['vehicle:none', 'Sem identificação veicular'];
        }

        $name = $vehicle->brand->name ?? 'Marca não informada';
        $key = 'brand:'.($vehicle->brand_id ?? 'none');
        if ($grouping === 'brand') {
            return [$key, $name];
        }

        $modelKey = $ticket->getAttribute('analytical_model_key');

        return [$key.':'.($modelKey ?? 'missing'), $name.' / '.($modelKey === null ? 'Modelo não informado' : $vehicle->model_description)];
    }

    /**
     * @param  Field  $definition
     * @return string|list<string>|null
     */
    private function value(SupportTicket $ticket, string $key, array $definition): string|array|null
    {
        if ($key === 'problem_subcategories') {
            $names = array_values($ticket->problemSubcategories->map(fn (ProblemSubcategory $subcategory): string => $subcategory->name)->all());

            return $names === [] ? null : $names;
        }

        $previous = $key === 'previous_ticket' ? $ticket->previousTicket : null;
        $value = match ($key) {
            'previous_ticket' => $previous === null ? null : '#'.str_pad((string) $previous->id, 4, '0', STR_PAD_LEFT).' · '.$previous->title,
            'vehicle_line' => $ticket->vehicle?->vehicle_line?->value,
            'verified_at' => $ticket->verified_at?->toDateTimeString(),
            default => data_get($ticket, $definition['path'] ?? $definition['column']),
        };

        return is_string($value) && preg_match('/\A[\s\p{Z}]*\z/u', $value) !== 1 ? $value : null;
    }
}
