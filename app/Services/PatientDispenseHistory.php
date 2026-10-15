<?php

namespace App\Services;

use App\Models\DispenseLog;
use App\Models\Medication;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// A patient's medications plus every actual dispense in a date range, in
// Thai local time. Backs both the in-app per-patient history and the
// medication administration record (MAR) Excel export, so both always agree.
class PatientDispenseHistory
{
    public const TIMEZONE = 'Asia/Bangkok';
    public const MAX_DAYS = 31;

    // The 4 daily rounds of the paper MAR form, in column order.
    public const ROUNDS = ['morning', 'noon', 'evening', 'bedtime'];
    public const ROUND_LABELS = [
        'morning' => 'เช้า',
        'noon' => 'กลางวัน',
        'evening' => 'เย็น',
        'bedtime' => 'ก่อนนอน',
    ];

    private const MEAL_ROUND = [
        'breakfast' => 'morning',
        'lunch' => 'noon',
        'dinner' => 'evening',
        'bedtime' => 'bedtime',
    ];

    public const MEAL_LABELS = [
        'breakfast_before' => 'เช้า ก่อนอาหาร',
        'breakfast_after' => 'เช้า หลังอาหาร',
        'lunch_before' => 'กลางวัน ก่อนอาหาร',
        'lunch_after' => 'กลางวัน หลังอาหาร',
        'dinner_before' => 'เย็น ก่อนอาหาร',
        'dinner_after' => 'เย็น หลังอาหาร',
        'bedtime_before' => 'ก่อนนอน ก่อนอาหาร',
        'bedtime_after' => 'ก่อนนอน หลังอาหาร',
        'prn' => 'ตามแพทย์สั่ง (PRN)',
    ];

    private const THAI_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    public Patient $patient;
    public CarbonImmutable $from;
    public CarbonImmutable $to;

    public function __construct(Patient $patient, CarbonImmutable $from, CarbonImmutable $to)
    {
        $this->patient = $patient;
        $this->from = $from->startOfDay();
        $this->to = $to->startOfDay();
    }

    // Default range: the last 7 days including today (Thai time).
    public static function fromRequestDates(Patient $patient, ?string $from, ?string $to): self
    {
        // Carbon::now (not CarbonImmutable::now) so a frozen test clock applies.
        $end = $to ? CarbonImmutable::parse($to, self::TIMEZONE) : CarbonImmutable::instance(\Illuminate\Support\Carbon::now(self::TIMEZONE));
        $start = $from ? CarbonImmutable::parse($from, self::TIMEZONE) : $end->subDays(6);

        return new self($patient, $start, $end);
    }

    /** @return CarbonImmutable[] every day in the range, oldest first */
    public function days(): array
    {
        $days = [];
        for ($day = $this->from; $day->lte($this->to); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /** Current medications, ordered by meal then name, with their cassette's meal loaded. */
    public function medications(): Collection
    {
        $mealOrder = array_flip(array_keys(self::MEAL_LABELS));

        return $this->patient->medications()->with('mealCassette')->get()
            ->sortBy(fn (Medication $m) => sprintf('%02d %s', $mealOrder[$m->mealCassette->meal ?? 'prn'] ?? 99, $m->drug_name))
            ->values();
    }

    /**
     * Actual dispenses (not verification scans) in the range, newest first.
     * Each: ['id', 'at' (CarbonImmutable, Thai time), 'medication' (?Medication), 'nurse_name'].
     */
    public function dispenses(): Collection
    {
        $startUtc = $this->from->startOfDay()->utc();
        $endUtc = $this->to->endOfDay()->utc();

        return DispenseLog::with(['medication.mealCassette', 'nurse'])
            ->where('patient_id', $this->patient->id)
            ->whereNotNull('medication_id')
            ->where('result', 'correct')
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DispenseLog $log) => [
                'id' => $log->id,
                'at' => CarbonImmutable::instance($log->created_at)->setTimezone(self::TIMEZONE),
                'medication' => $log->medication,
                'nurse_name' => $log->nurse->full_name ?? null,
            ]);
    }

    public static function mealOf(?Medication $medication): ?string
    {
        return $medication && $medication->mealCassette ? $medication->mealCassette->meal : null;
    }

    // Which of the 4 rounds a fixed meal belongs to (null for PRN / no meal).
    public static function roundOfMeal(?string $meal): ?string
    {
        if (!$meal || $meal === 'prn') {
            return null;
        }

        return self::MEAL_ROUND[explode('_', $meal)[0]] ?? null;
    }

    // Round a dispense happened in by clock time - used for PRN drugs and
    // drugs with no meal, which have no fixed round of their own.
    public static function roundOfTime(CarbonImmutable $at): string
    {
        $hour = (int) $at->format('G');
        if ($hour < 11) {
            return 'morning';
        }
        if ($hour < 16) {
            return 'noon';
        }

        return $hour < 20 ? 'evening' : 'bedtime';
    }

    public static function timingLabel(?string $meal): string
    {
        if ($meal && Str::endsWith($meal, '_before')) {
            return 'ก่อนอาหาร';
        }
        if ($meal && Str::endsWith($meal, '_after')) {
            return 'หลังอาหาร';
        }

        return 'ให้ยา';
    }

    public static function thaiDate(CarbonImmutable $day): string
    {
        return $day->day . ' ' . self::THAI_MONTHS[$day->month - 1] . ' ' . substr((string) ($day->year + 543), -2);
    }
}
