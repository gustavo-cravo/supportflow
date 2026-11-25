<?php

namespace App\Support;

/**
 * @phpstan-type Grouping array{by: string, format?: string}
 * @phpstan-type SectionInput array{enabled: bool, indicators?: array<string, array{enabled: bool, format?: string}>, grouping?: array{primary?: Grouping, additional?: list<Grouping>}}
 * @phpstan-type ConfigurationInput array{sections: array<string, SectionInput>, title?: array{mode?: string, text?: ?string}, subtitle?: ?string}
 * @phpstan-type Section array{enabled: true, indicators: array<string, array{enabled: true, format?: string}>, grouping?: array{primary: Grouping, additional: list<Grouping>}}
 */
final readonly class TicketMetricsPdfConfiguration
{
    /**
     * @param  array<string, Section>  $sections
     * @param  array{mode: string, text: ?string}  $title
     */
    private function __construct(public array $sections, public array $title, public ?string $subtitle) {}

    /** @param ConfigurationInput $input */
    public static function fromValidated(array $input): self
    {
        $sections = [];
        foreach (TicketMetricsPdfCatalog::sections() as $key => $definition) {
            $selection = $input['sections'][$key] ?? null;
            if ($selection === null || ! $selection['enabled']) {
                continue;
            }

            $indicators = [];
            foreach ($definition['indicators'] as $id => $option) {
                $indicator = $selection['indicators'][$id] ?? null;
                if ($indicator !== null && $indicator['enabled']) {
                    $indicators[$id] = ['enabled' => true];
                    if ($option['formats'] !== []) {
                        $indicators[$id]['format'] = $indicator['format'] ?? TicketMetricsPdfCatalog::AUTOMATIC;
                    }
                }
            }

            if ($key === TicketMetricsPdfCatalog::VEHICLE_PROBLEMS) {
                if (isset($selection['grouping']['primary'])) {
                    $sections[$key] = ['enabled' => true, 'indicators' => $indicators, 'grouping' => [
                        'primary' => self::normalizeGrouping($selection['grouping']['primary']),
                        'additional' => array_map(self::normalizeGrouping(...), $selection['grouping']['additional'] ?? []),
                    ]];
                }
            } elseif ($indicators !== []) {
                $sections[$key] = ['enabled' => true, 'indicators' => $indicators];
            }
        }

        return new self(
            $sections,
            [
                'mode' => $input['title']['mode'] ?? TicketMetricsPdfCatalog::AUTOMATIC,
                'text' => $input['title']['text'] ?? null,
            ],
            $input['subtitle'] ?? null,
        );
    }

    /** @return array{sections: array<string, Section>, title: array{mode: string, text: ?string}, subtitle: ?string} */
    public function toArray(): array
    {
        return ['sections' => $this->sections, 'title' => $this->title, 'subtitle' => $this->subtitle];
    }

    /**
     * @param  Grouping  $group
     * @return array{by: string, format: string}
     */
    private static function normalizeGrouping(array $group): array
    {
        return ['by' => $group['by'], 'format' => $group['format'] ?? TicketMetricsPdfCatalog::AUTOMATIC];
    }
}
