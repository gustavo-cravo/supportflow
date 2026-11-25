<?php

namespace App\Services\Reports;

use App\Support\TicketMetricsPdfCatalog;
use LogicException;

final class TicketMetricsPdfLayout
{
    private const COLORS = ['#245cda', '#667fbb'];

    /** @param list<array{name: string, value: int|float, vehicles?: int}> $rows */
    public static function seriesFormat(string $metric, string $requested, array $rows): string
    {
        if ($requested !== TicketMetricsPdfCatalog::AUTOMATIC) {
            return $requested;
        }
        if ($metric === 'lines') {
            return array_sum(array_column($rows, 'value')) > 0 ? 'doughnut' : 'table';
        }
        if ($metric !== 'models' && count($rows) >= 2 && count($rows) <= 8
            && max(array_map(static fn (array $row): int => mb_strlen($row['name']), $rows)) <= 125) {
            return 'horizontal_bar';
        }

        return 'table';
    }

    /** @return list<string> */
    public static function chunks(string $label, int $max = 220): array
    {
        $parts = [];
        while (mb_strlen($label) > $max) {
            $part = mb_substr($label, 0, $max);
            if (preg_match('/^(.+)\s\S*$/us', $part, $match) && mb_strlen($match[1]) >= $max / 2) {
                $part = $match[1];
            }
            $parts[] = $part;
            $label = mb_substr($label, mb_strlen($part));
        }
        $parts[] = $label;

        return $parts;
    }

    public static function formatValue(int|float $value, string $unit): string
    {
        return str_starts_with($unit, '%') ? TicketMetricsPdfProjection::percentage($value) : TicketMetricsPdfProjection::number($value);
    }

    /**
     * Charts use short numeric keys. Catalog labels live in flowing HTML and can span pages.
     *
     * @param  list<int|float>  $values
     */
    public function bars(array $values, float $maximum, bool $horizontal, int $offset): string
    {
        $parts = [];
        $height = $horizontal ? 18 + 19 * count($values) : 108;
        if ($horizontal) {
            $parts[] = '<path d="M30 5v'.($height - 15).'" stroke="#cbd5e1"/>';
            foreach ($values as $i => $value) {
                $y = 7 + $i * 19;
                $parts[] = $this->text(20, $y + 9, (string) ($offset + $i + 1), 'end');
                $parts[] = $this->rectangle(30, $y, 393 * $value / $maximum, 11);
            }
        } else {
            $parts[] = '<path d="M24 5v81h405" stroke="#cbd5e1" fill="none"/>';
            $step = 394 / count($values);
            $width = min(35, $step / 2);
            foreach ($values as $i => $value) {
                $x = 30 + ($i + .5) * $step;
                $bar = 78 * $value / $maximum;
                $parts[] = $this->rectangle($x - $width / 2, 86 - $bar, $width, $bar);
                $parts[] = $this->text($x, 101, (string) ($offset + $i + 1), 'middle');
            }
        }

        return $this->image(445, $height, implode('', $parts));
    }

    /** @param list<int|float> $values */
    public function doughnut(array $values): string
    {
        $total = array_sum($values);
        if ($total <= 0) {
            throw new LogicException('A doughnut chart requires a positive total.');
        }
        $angle = -M_PI / 2;
        $parts = [];
        foreach ($values as $i => $value) {
            if ($value <= 0) {
                continue;
            }
            $color = self::COLORS[$i];
            if ($value === $total) {
                $parts[] = '<circle cx="55" cy="55" r="38" fill="none" stroke="'.$color.'" stroke-width="24"/>';
                $parts[] = $this->text(55, 96, (string) ($i + 1), 'middle', '#fff');

                continue;
            }
            $sweep = 2 * M_PI * $value / $total;
            $end = $angle + $sweep;
            $large = (int) ($sweep > M_PI);
            $path = 'M'.$this->point(50, $angle).'A50 50 0 '.$large.' 1 '.$this->point(50, $end)
                .'L'.$this->point(26, $end).'A26 26 0 '.$large.' 0 '.$this->point(26, $angle).'Z';
            $parts[] = '<path d="'.$path.'" fill="'.$color.'" stroke="#fff" stroke-width="1"/>';
            $middle = ($angle + $end) / 2;
            // Tiny slices still have a numeric and textual key in the legend.
            if ($sweep >= .25) {
                $parts[] = $this->text(55 + 38 * cos($middle), 58 + 38 * sin($middle), (string) ($i + 1), 'middle', '#fff');
            }
            $angle = $end;
        }

        return $this->image(110, 110, implode('', $parts));
    }

    public function doughnutColor(int $index): string
    {
        return self::COLORS[$index];
    }

    private function point(int $radius, float $angle): string
    {
        return sprintf('%.3F %.3F', 55 + $radius * cos($angle), 55 + $radius * sin($angle));
    }

    private function rectangle(float $x, float $y, float $width, float $height): string
    {
        return sprintf('<rect x="%.3F" y="%.3F" width="%.3F" height="%.3F" rx="2" fill="%s"/>', $x, $y, $width, $height, self::COLORS[0]);
    }

    private function text(float $x, float $y, string $label, string $anchor, string $color = '#28364a'): string
    {
        return sprintf('<text x="%.3F" y="%.3F" text-anchor="%s" fill="%s" font-family="DejaVu Sans" font-size="9">%s</text>', $x, $y, $anchor, $color, $label);
    }

    private function image(int $width, int $height, string $content): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="'.$width
            .'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">'.$content.'</svg>');
    }
}
