<?php

namespace App\Services\Reports;

use App\Support\VehicleMetricsReportTables;
use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type PublishedField from AnalyticalTicketReportProjection
 * @phpstan-import-type PublishedTicket from AnalyticalTicketReportProjection
 *
 * @phpstan-type Section array{label: string, absence: ?string, rows: list<non-empty-list<PublishedField>>}
 */
final class AnalyticalTicketPdfLayout
{
    /** @param PublishedTicket $ticket
     * @return array{title: ?string, sections: list<Section>, keep: bool}
     */
    public function ticket(array $ticket, string $columns): array
    {
        $title = $ticket['sections']['ticket']['fields']['title']['value'] ?? null;
        if (is_string($title)) {
            unset($ticket['sections']['ticket']['fields']['title']);
        }
        $sections = [];
        $lines = 3 + (is_string($title) ? $this->estimateLines($title, 70) : 0);
        foreach ($ticket['sections'] as $section) {
            $fields = $section['fields'];
            if ($fields === [] && $section['absence'] === null) {
                continue;
            }

            foreach ($fields as $id => &$field) {
                if (is_string($field['value'])) {
                    $field['value'] = $this->displayValue($id, $field['value']);
                }
            }
            unset($field);

            $short = count(array_filter($fields, $this->canPair(...)));
            $twoColumns = $columns === 'two' || ($columns === 'automatic' && $short >= 3);
            $rows = $this->rows(array_values($fields), $twoColumns);
            $sections[] = ['label' => $section['label'], 'absence' => $section['absence'], 'rows' => $rows];
            $lines += 2 + (int) ($section['absence'] !== null);
            foreach ($rows as $row) {
                $width = count($row) === 2 ? 40 : 85;
                $lines += 1 + max(array_map(fn (array $field): int => $this->estimateLines($this->valueText($field), $width), $row));
            }
        }

        return ['title' => is_string($title) ? $title : null, 'sections' => $sections, 'keep' => $lines <= 24];
    }

    /**
     * @param  list<PublishedField>  $fields
     * @return list<non-empty-list<PublishedField>>
     */
    private function rows(array $fields, bool $twoColumns): array
    {
        $rows = [];
        $pending = null;
        foreach ($fields as $field) {
            if ($twoColumns && $this->canPair($field)) {
                if ($pending === null) {
                    $pending = $field;
                } else {
                    $rows[] = [$pending, $field];
                    $pending = null;
                }

                continue;
            }
            if ($pending !== null) {
                $rows[] = [$pending];
                $pending = null;
            }
            $rows[] = [$field];
        }
        if ($pending !== null) {
            $rows[] = [$pending];
        }

        return $rows;
    }

    /** @param PublishedField $field */
    private function canPair(array $field): bool
    {
        // Dompdf cannot split a table row. Exceptional values always flow in a regular block.
        return $field['width'] === 'columns' && $this->estimateLines($this->valueText($field), 40) <= 4;
    }

    /** @param PublishedField $field */
    private function valueText(array $field): string
    {
        return is_array($field['value']) ? implode("\n", $field['value']) : ($field['value'] ?? $field['absence'] ?? '');
    }

    private function estimateLines(string $value, int $width): int
    {
        $lines = preg_split('/\R/u', $value) ?: [$value];

        return array_sum(array_map(static fn (string $line): int => max(1, (int) ceil(mb_strlen($line) / $width)), $lines));
    }

    private function displayValue(string $field, string $value): string
    {
        return match ($field) {
            'vehicle_line' => VehicleMetricsReportTables::lineLabel($value),
            'verification_source' => match ($value) {
                'customer' => 'Cliente',
                'responsible_department' => 'Setor responsável',
                default => $value,
            },
            'verified_at' => CarbonImmutable::parse($value)->format('d/m/Y H:i:s'),
            default => $value,
        };
    }
}
