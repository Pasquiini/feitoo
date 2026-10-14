<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait WritesFiscalWorkbook
{
    protected function row(Worksheet $sheet, int $row, array $values, array $decimals = [], array $numbers = []): void
    {
        foreach ($values as $index => $value) {
            $column = $index + 1;
            $type = DataType::TYPE_STRING;
            if ($value === null) {
                continue;
            }
            if (in_array($column, $numbers, true) || (in_array($column, $decimals, true) && $this->safeDecimal($value))) {
                $type = DataType::TYPE_NUMERIC;
            }
            // Explicit strings bypass formula detection, including =, +, -, @.
            $sheet->setCellValueExplicit([$column, $row], $value, $type);
        }
    }

    protected function safeDecimal(string $value): bool
    {
        return preg_match('/\A-?\d+(?:\.\d+)?\z/D', $value) === 1
            && strlen(ltrim(str_replace(['-', '.'], '', $value), '0')) <= 15;
    }

    protected function format(Worksheet $sheet, int $lastRow, int $columns, array $decimals, ?int $dateColumn = 4): void
    {
        $end = Coordinate::stringFromColumnIndex($columns);
        $sheet->freezePane('A2')->setAutoFilter('A1:'.$end.$lastRow);
        $sheet->getStyle('A1:'.$end.'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '334155']],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getStyle('A1:'.$end.'1')->getAlignment()->setWrapText(true);
        for ($column = 1; $column <= $columns; $column++) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->getColumnDimension($letter)->setWidth($column === 1 ? 48 : 22);
        }
        if ($dateColumn !== null) {
            $letter = Coordinate::stringFromColumnIndex($dateColumn);
            $sheet->getStyle($letter.'2:'.$letter.$lastRow)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        }
        foreach ($decimals as $column) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->getStyle($letter.'2:'.$letter.$lastRow)->getNumberFormat()->setFormatCode('#,##0.00########');
        }
    }
}
