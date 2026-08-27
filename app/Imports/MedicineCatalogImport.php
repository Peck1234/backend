<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Deliberately reads raw rows (ToCollection) instead of WithHeadingRow -
 * WithHeadingRow slugs headers via Str::slug, which strips non-ASCII
 * characters and would blank out every Thai column header. Header matching
 * is done manually below against the literal Thai header text instead.
 */
class MedicineCatalogImport implements ToCollection
{
    public const REQUIRED_HEADERS = [
        'drug_name' => 'ชื่อยา',
        'standard_dose' => 'ขนาดมาตรฐาน',
    ];

    public const OPTIONAL_HEADERS = [
        'generic_name' => 'ชื่อสามัญ',
        'category' => 'หมวดหมู่',
        'purpose' => 'สรรพคุณ',
        'contraindication' => 'ข้อห้ามใช้',
    ];

    /** @var array|null null until a sheet has been read; then the parsed field => row-column-index map */
    public $columnMap = null;

    /** @var array<int, string> row-number (1-based, header excluded) => reason, for header rows that could not be matched at all */
    public $headerError = null;

    /** @var Collection<int, array> parsed rows, keyed by their 1-based data-row number (header = row 1) */
    public $rows;

    public function collection(Collection $sheet)
    {
        $this->rows = collect();

        if ($sheet->isEmpty()) {
            $this->headerError = 'ไฟล์ว่างเปล่า ไม่พบแถวหัวตาราง';
            return;
        }

        $header = $sheet->first()->map(function ($cell) {
            return is_string($cell) ? trim($cell) : $cell;
        });

        $allHeaders = self::REQUIRED_HEADERS + self::OPTIONAL_HEADERS;
        $map = [];
        foreach ($allHeaders as $field => $label) {
            $index = $header->search($label);
            if ($index !== false) {
                $map[$field] = $index;
            }
        }

        $missing = [];
        foreach (self::REQUIRED_HEADERS as $field => $label) {
            if (!isset($map[$field])) {
                $missing[] = $label;
            }
        }

        if (!empty($missing)) {
            $this->headerError = 'ไฟล์ขาดคอลัมน์ที่จำเป็น: ' . implode(', ', $missing);
            return;
        }

        $this->columnMap = $map;

        // skip() preserves the original zero-based keys rather than reindexing,
        // so without values() here $offset would start at 1 (not 0) and every
        // reported row number below would be off by one.
        foreach ($sheet->skip(1)->values() as $offset => $row) {
            $rowNumber = $offset + 2; // +1 for the skipped header, +1 for 1-based numbering
            $isBlank = collect($map)->every(function ($index) use ($row) {
                $value = $row->get($index);
                return $value === null || trim((string) $value) === '';
            });

            if ($isBlank) {
                continue;
            }

            $data = [];
            foreach ($map as $field => $index) {
                $value = $row->get($index);
                $value = is_string($value) ? trim($value) : $value;
                $data[$field] = ($value === '' ? null : $value);
            }

            $this->rows->put($rowNumber, $data);
        }
    }
}
