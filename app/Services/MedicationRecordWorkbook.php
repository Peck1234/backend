<?php

namespace App\Services;

use App\Models\Medication;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Builds the editable Excel medication administration record (MAR) for one
// patient, laid out like the ward's paper form: one row per drug, and for
// each day four columns (เช้า กลางวัน เย็น ก่อนนอน). A cell shows when the drug
// is due in that round (ก่อน/หลังอาหาร) and, once given, "✓ HH:MM". A second
// sheet lists every dispense with the nurse who gave it.
class MedicationRecordWorkbook
{
    private const FONT = 'Tahoma';
    private const HEADER_FILL = 'E8EAF2';
    private const GIVEN_FILL = 'E3F2E7';

    private PatientDispenseHistory $history;

    public function __construct(PatientDispenseHistory $history)
    {
        $this->history = $history;
    }

    // Export 1: dispense history - the MAR form plus every dispense.
    public function buildHistory(): Spreadsheet
    {
        $book = $this->newBook('แบบบันทึกการให้ยาผู้ป่วย');
        $dispenses = $this->history->dispenses();
        $this->buildRecordSheet($book->getActiveSheet(), $dispenses);
        $this->buildHistorySheet($book->createSheet(), $dispenses);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function historyFileName(): string
    {
        return sprintf('ประวัติการจ่ายยา-%s-%s_%s.xlsx', $this->safePatientName(),
            $this->history->from->format('Y-m-d'), $this->history->to->format('Y-m-d'));
    }

    private function newBook(string $title): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);
        $book->getProperties()->setTitle($title)->setCreator('care URU');

        return $book;
    }

    private function safePatientName(): string
    {
        return preg_replace('/[\\\\\\/:*?"<>|]+/u', '', $this->history->patient->full_name) ?: 'patient';
    }

    private function buildRecordSheet(Worksheet $sheet, $dispenses): void
    {
        $sheet->setTitle('บันทึกการให้ยา');
        $patient = $this->history->patient;
        $days = $this->history->days();
        $lastCol = 2 + count($days) * 4;
        $last = Coordinate::stringFromColumnIndex($lastCol);

        // Given dispenses, indexed by medication and Thai date.
        $given = [];
        foreach ($dispenses as $d) {
            $given[$d['medication']->id][$d['at']->format('Y-m-d')][] = $d['at'];
        }

        $sheet->setCellValue('A1', 'แบบบันทึกการให้ยาผู้ป่วย');
        $sheet->mergeCells("A1:{$last}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', sprintf(
            'ชื่อ-สกุล: %s      วอร์ด: %s      เตียง: %s      วันที่ %s – %s',
            $patient->full_name,
            $patient->ward ?: '-',
            $patient->bed_no ?: '-',
            PatientDispenseHistory::thaiDate($this->history->from),
            PatientDispenseHistory::thaiDate($this->history->to)
        ));
        $sheet->mergeCells("A2:{$last}2");

        $sheet->setCellValue('A3', 'ลำดับ');
        $sheet->mergeCells('A3:A4');
        $sheet->setCellValue('B3', "ชื่อยา / ขนาด / วิธีใช้");
        $sheet->mergeCells('B3:B4');
        foreach ($days as $i => $day) {
            $first = 3 + $i * 4;
            $sheet->setCellValueByColumnAndRow($first, 3, PatientDispenseHistory::thaiDate($day));
            $sheet->mergeCellsByColumnAndRow($first, 3, $first + 3, 3);
            foreach (PatientDispenseHistory::ROUNDS as $r => $round) {
                $sheet->setCellValueByColumnAndRow($first + $r, 4, PatientDispenseHistory::ROUND_LABELS[$round]);
            }
        }

        $row = 5;
        foreach ($this->drugRows() as $index => $drug) {
            $sheet->setCellValueByColumnAndRow(1, $row, $index + 1);
            $sheet->setCellValueByColumnAndRow(2, $row, $drug['label']);

            foreach ($days as $i => $day) {
                $date = $day->format('Y-m-d');
                foreach (PatientDispenseHistory::ROUNDS as $r => $round) {
                    $col = 3 + $i * 4 + $r;
                    $lines = [];
                    $wasGiven = false;
                    foreach ($drug['medications'] as $m) {
                        $meal = PatientDispenseHistory::mealOf($m);
                        $times = $given[$m->id][$date] ?? [];
                        $fixedRound = PatientDispenseHistory::roundOfMeal($meal);

                        if ($fixedRound !== null) {
                            if ($fixedRound !== $round) {
                                continue;
                            }
                            $line = PatientDispenseHistory::timingLabel($meal);
                            foreach ($times as $at) {
                                $line .= ' ✓ ' . $at->format('H:i');
                                $wasGiven = true;
                            }
                            $lines[] = $line;
                        } else {
                            foreach ($times as $at) {
                                if (PatientDispenseHistory::roundOfTime($at) === $round) {
                                    $lines[] = 'ให้ยา ✓ ' . $at->format('H:i');
                                    $wasGiven = true;
                                }
                            }
                        }
                    }
                    $sheet->setCellValueByColumnAndRow($col, $row, $lines ? implode("\n", $lines) : '-');
                    if ($wasGiven) {
                        $sheet->getStyleByColumnAndRow($col, $row)->getFill()
                            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GIVEN_FILL);
                    }
                }
            }
            $row++;
        }
        $lastRow = max($row - 1, 4);
        if ($row === 5) {
            $sheet->setCellValue('B5', 'ผู้ป่วยรายนี้ยังไม่มีรายการยา');
            $lastRow = 5;
        }

        $table = $sheet->getStyle("A3:{$last}{$lastRow}");
        $table->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $table->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("A3:{$last}4")->getFont()->setBold(true);
        $sheet->getStyle("A3:{$last}4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        $sheet->getStyle("A3:{$last}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A5:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C5:{$last}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $note = $lastRow + 2;
        $sheet->setCellValue("A{$note}", 'หมายเหตุ: "✓ เวลา" = จ่ายยาแล้ว (ช่องสีเขียว) · ก่อน/หลังอาหาร ที่ไม่มี ✓ = ยังไม่ได้จ่าย · "-" = ไม่มียานี้ในมื้อนี้ · ยาเมื่อมีอาการแสดงในมื้อตามเวลาที่จ่ายจริง');
        $sheet->mergeCells("A{$note}:{$last}{$note}");
        $sheet->getStyle("A{$note}")->getFont()->setItalic(true)->setSize(9);

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(36);
        for ($c = 3; $c <= $lastCol; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(11);
        }
        $sheet->freezePane('C5');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(3, 4);
    }

    // One row per drug + dose, merging the same drug given in several meals
    // (each meal is its own Medication row in the database).
    private function drugRows(): array
    {
        $rows = [];
        foreach ($this->history->medications() as $m) {
            $key = mb_strtolower(trim($m->drug_name) . '|' . trim((string) $m->dose));
            if (!isset($rows[$key])) {
                // Just the drug (name, dose, how to take it) - which meal and
                // before/after food is already in each day's round cells.
                $lines = [trim($m->drug_name) . ($m->dose ? ' ' . trim($m->dose) : '')];
                if ($m->instruction) {
                    $lines[] = trim($m->instruction);
                } elseif ($m->standard_dose && $m->standard_dose !== $m->dose) {
                    $lines[] = trim($m->standard_dose);
                }
                $rows[$key] = ['label' => implode("\n", $lines), 'medications' => []];
            }
            $rows[$key]['medications'][] = $m;
        }

        return array_values($rows);
    }

    private function buildHistorySheet(Worksheet $sheet, $dispenses): void
    {
        $sheet->setTitle('ประวัติการจ่ายยา');
        $headers = ['วันที่', 'เวลา', 'ชื่อยา', 'ขนาด', 'มื้อ', 'พยาบาลผู้จ่าย'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
        }

        $row = 2;
        foreach ($dispenses->sortBy(fn ($d) => $d['at']->getTimestamp())->values() as $d) {
            /** @var Medication $m */
            $m = $d['medication'];
            $meal = PatientDispenseHistory::mealOf($m);
            $values = [
                PatientDispenseHistory::thaiDate($d['at']),
                $d['at']->format('H:i'),
                $m->drug_name,
                $m->dose ?: '-',
                PatientDispenseHistory::MEAL_LABELS[$meal] ?? '-',
                $d['nurse_name'] ?: '-',
            ];
            foreach ($values as $i => $v) {
                $sheet->setCellValueExplicitByColumnAndRow($i + 1, $row, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $row++;
        }
        if ($row === 2) {
            $sheet->setCellValue('A2', 'ไม่มีการจ่ายยาในช่วงวันที่นี้');
            $row = 3;
        }

        $range = 'A1:F' . ($row - 1);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        foreach (['A' => 12, 'B' => 8, 'C' => 32, 'D' => 18, 'E' => 20, 'F' => 26] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($range);
    }
}
