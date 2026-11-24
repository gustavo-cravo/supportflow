<?php

namespace App\Services\Reports;

use App\Support\TicketMetricsReportData;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class XlsxTicketMetricsReport
{
    public function render(TicketMetricsReportData $report): string
    {
        $book = $this->workbook($report);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            $book->disconnectWorksheets();
            throw new RuntimeException('Could not allocate report stream.');
        }
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($stream);
            rewind($stream);
            $bytes = stream_get_contents($stream);
            if ($bytes === false) {
                throw new RuntimeException('Could not read report stream.');
            }

            return $bytes;
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
        }
    }

    public function workbook(TicketMetricsReportData $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('SupportFlow')->setLastModifiedBy('SupportFlow')
            ->setTitle(TicketMetricsReportData::TITLE)->setSubject('Indicadores estatísticos consolidados')
            ->setCreated($report->generatedAt->getTimestamp())->setModified($report->generatedAt->getTimestamp());
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11)->getColor()->setRGB('202B40');
        $summary = $book->getActiveSheet()->setTitle('Resumo Geral');
        $this->setup($summary);
        foreach (['A' => 29, 'B' => 29, 'C' => 29, 'D' => 24] as $column => $width) {
            $summary->getColumnDimension($column)->setWidth($width);
        }
        $this->text($summary, 'A1', 'SupportFlow');
        $summary->mergeCells('A1:D1')->getStyle('A1')->getFont()->setSize(21)->setBold(true)->getColor()->setRGB('245CDA');
        $summary->getRowDimension(1)->setRowHeight(32);
        $this->text($summary, 'A2', TicketMetricsReportData::TITLE);
        $summary->mergeCells('A2:D2')->getStyle('A2')->getFont()->setSize(15)->setBold(true);
        $summary->getRowDimension(2)->setRowHeight(40);
        $this->text($summary, 'A3', 'Gerado em '.$report->generatedAt->format('d/m/Y H:i').' - Horário de Brasília');
        $summary->mergeCells('A3:D3')->getStyle('A3')->getFont()->setSize(10)->getColor()->setRGB('758096');
        foreach ($report->criteria as $index => $criterion) {
            $row = $index + 5;
            $this->text($summary, 'A'.$row, $criterion['label']);
            $this->text($summary, 'B'.$row, $criterion['value']);
            $summary->mergeCells('B'.$row.':D'.$row);
            $summary->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setRGB('758096');
            $summary->getRowDimension($row)->setRowHeight(max(30, ceil(mb_strlen($criterion['value']) / 75) * 15 + 8));
        }
        $this->text($summary, 'A12', 'Indicador');
        $summary->mergeCells('A12:C12');
        $this->text($summary, 'D12', 'Quantidade');
        $this->header($summary, 'A12:D12');
        $row = 13;
        foreach (['total' => 'Total de atendimentos', 'closed' => 'Encerrados', 'not_closed' => 'Não encerrados', 'systems_count' => 'Sistemas com atendimentos', 'unclassified' => 'Sem classificação', 'subcategory_occurrences' => 'Vínculos com subcategorias'] as $key => $label) {
            $this->text($summary, 'A'.$row, $label);
            $summary->mergeCells('A'.$row.':C'.$row);
            $summary->setCellValueExplicit('D'.$row, $report->summary[$key], DataType::TYPE_NUMERIC);
            $summary->getStyle('D'.$row)->getNumberFormat()->setFormatCode('#,##0');
            $summary->getStyle('D'.$row)->getFont()->setSize(14)->setBold(true)->getColor()->setRGB('245CDA');
            $summary->getRowDimension($row)->setRowHeight(32);
            $row++;
        }
        $this->text($summary, 'A19', $report->summary['total'] === 0 ? 'Nenhum atendimento encontrado para os filtros selecionados.' : 'Resultados consolidados dos atendimentos, respeitando todos os filtros acima.');
        $summary->mergeCells('A19:D19')->getRowDimension(19)->setRowHeight(30);
        $summary->getStyle('A1:D19')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $summary->freezePane('A5');
        $summary->getPageSetup()->setPrintArea('A1:D19');

        $this->table($book, 'Por Sistema', ['Sistema', 'Atendimentos', 'Encerrados', 'Não encerrados', 'Participação no total'], array_map(fn (array $row): array => [$row['name'], $row['total'], $row['closed'], $row['not_closed'], $row['percentage'] / 100], $report->systems), [42, 20, 18, 22, 24], [5 => '0.00%']);
        $this->table($book, 'Categorias', ['Categoria', 'Ocorrências', 'Participação no total'], array_map(fn (array $row): array => [$row['name'], $row['total'], $row['percentage'] / 100], $report->categories), [64, 20, 25], [3 => '0.00%']);
        $rows = [];
        foreach ($report->categories as $category) {
            foreach ($category['subcategories'] as $subcategory) {
                foreach ($subcategory['systems'] as $system) {
                    $rows[] = [$category['name'], $subcategory['name'], $system['name'], $system['total'], $system['percentage'] / 100];
                }
            }
        }
        $this->table($book, 'Subcategorias', ['Categoria principal', 'Subcategoria', 'Sistema', 'Ocorrências', 'Participação na categoria'], $rows, [40, 40, 34, 18, 26], [5 => '0.00%'], 'Cada vínculo conta uma ocorrência. O percentual usa os atendimentos da categoria e pode somar mais de 100% entre as subcategorias.');
        $this->table($book, 'Evolução Mensal', ['Mês', 'Atendimentos'], array_map(fn (array $row): array => [$this->monthDate($row['month']), $row['total']], $report->monthly), [38, 28], [1 => 'mm/yyyy']);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function setup(Worksheet $sheet): void
    {
        $sheet->setShowGridlines(false)->setPrintGridlines(false);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.35)->setRight(0.35);
        $sheet->getHeaderFooter()->setOddFooter('&LSupportFlow&R Página &P de &N');
    }

    private function monthDate(string $month): float|string
    {
        // Excel cannot display dates before its 1900 epoch; preserve those month labels as ISO text.
        if (substr($month, 0, 4) < '1900') {
            return $month;
        }
        $serial = Date::PHPToExcel(CarbonImmutable::createFromFormat('!Y-m', $month));
        if ($serial === false) {
            throw new RuntimeException('Invalid monthly date.');
        }

        return $serial;
    }

    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        // Never let registered names become spreadsheet formulas, links or executable content.
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function header(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('245CDA');
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     * @param  list<int>  $widths
     * @param  array<int, string>  $formats
     */
    private function table(Spreadsheet $book, string $title, array $headers, array $rows, array $widths, array $formats = [], ?string $note = null): void
    {
        $sheet = $book->createSheet()->setTitle($title);
        $this->setup($sheet);
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $lastRow = max(5, count($rows) + 4);
        $this->text($sheet, 'A1', 'SupportFlow | '.$title);
        $sheet->mergeCells('A1:'.$lastColumn.'1')->getStyle('A1')->getFont()->setSize(17)->setBold(true)->getColor()->setRGB('245CDA');
        $sheet->getRowDimension(1)->setRowHeight(34);
        $this->text($sheet, 'A2', $note ?? 'Resultados consolidados conforme os filtros identificados na planilha Resumo Geral.');
        $sheet->mergeCells('A2:'.$lastColumn.'2')->getRowDimension(2)->setRowHeight(36);
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('758096');
        foreach ($headers as $index => $header) {
            $this->text($sheet, Coordinate::stringFromColumnIndex($index + 1).'4', $header);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($widths[$index]);
        }
        $this->header($sheet, 'A4:'.$lastColumn.'4');
        $sheet->getRowDimension(4)->setRowHeight(34);
        foreach ($rows as $index => $values) {
            $lineCount = 1;
            foreach ($values as $column => $value) {
                $cell = Coordinate::stringFromColumnIndex($column + 1).($index + 5);
                if (is_string($value)) {
                    $this->text($sheet, $cell, $value);
                    $lineCount = max($lineCount, (int) ceil(mb_strlen($value) / max(8, $widths[$column] - 4)));
                } else {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($formats[$column + 1] ?? '#,##0');
                }
            }
            $sheet->getRowDimension($index + 5)->setRowHeight(max(29, $lineCount * 16 + 8));
            if ($index % 2 === 1) {
                $sheet->getStyle('A'.($index + 5).':'.$lastColumn.($index + 5))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F6F8FC');
            }
        }
        if ($rows === []) {
            $this->text($sheet, 'A5', 'Nenhuma ocorrência para os filtros selecionados.');
            $sheet->mergeCells('A5:'.$lastColumn.'5')->getRowDimension(5)->setRowHeight(30);
        } else {
            $sheet->setAutoFilter('A4:'.$lastColumn.$lastRow);
        }
        $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A5');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 4)->setPrintArea('A1:'.$lastColumn.$lastRow)
            ->setOrientation(count($headers) >= 5 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
    }
}
