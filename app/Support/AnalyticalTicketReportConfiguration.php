<?php

namespace App\Support;

/**
 * @phpstan-type ConfigurationInput array{selection?: array{mode: string, ids?: list<int>}, sections?: array<string, array{enabled: bool, fields?: list<string>}>, grouping?: string, order?: string, empty_fields?: string, title?: array{mode?: string, text?: ?string}, subtitle?: ?string, layout?: array{pagination?: string, columns?: string, spacing?: string}}
 */
final readonly class AnalyticalTicketReportConfiguration
{
    /**
     * @param  array{mode: string, ids: list<int>}  $selection
     * @param  array<string, array{enabled: true, fields: list<string>}>  $sections
     * @param  array{mode: string, text: ?string}  $title
     * @param  array{pagination: string, columns: string, spacing: string}  $layout
     */
    private function __construct(
        public array $selection,
        public array $sections,
        public string $grouping,
        public string $order,
        public string $emptyFields,
        public array $title,
        public ?string $subtitle,
        public array $layout,
    ) {}

    /** @param ConfigurationInput $input */
    public static function fromValidated(array $input): self
    {
        $sections = [];
        foreach (AnalyticalTicketReportCatalog::sections() as $id => $section) {
            $selected = $input['sections'][$id] ?? ['enabled' => ! isset($input['sections'])];
            $all = array_keys($section['fields']);
            $fields = array_values(array_intersect($all, $selected['fields'] ?? $all));
            if ($selected['enabled'] && $fields !== []) {
                $sections[$id] = ['enabled' => true, 'fields' => $fields];
            }
        }
        $mode = $input['title']['mode'] ?? 'automatic';

        return new self(
            ['mode' => $input['selection']['mode'] ?? 'all', 'ids' => array_values(array_unique($input['selection']['ids'] ?? []))],
            $sections,
            $input['grouping'] ?? 'none',
            $input['order'] ?? 'newest',
            $input['empty_fields'] ?? 'smart',
            ['mode' => $mode, 'text' => $mode === 'custom' ? ($input['title']['text'] ?? null) : null],
            $input['subtitle'] ?? null,
            [...['pagination' => 'automatic', 'columns' => 'automatic', 'spacing' => 'normal'], ...($input['layout'] ?? [])],
        );
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_merge([], ...array_column($this->sections, 'fields'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'selection' => $this->selection, 'sections' => $this->sections,
            'grouping' => $this->grouping, 'order' => $this->order, 'empty_fields' => $this->emptyFields,
            'title' => $this->title, 'subtitle' => $this->subtitle, 'layout' => $this->layout,
        ];
    }
}
