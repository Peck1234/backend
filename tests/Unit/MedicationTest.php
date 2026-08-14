<?php

namespace Tests\Unit;

use App\Models\Medication;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Unit tests for Medication's due/dispensed logic - what the "ยาที่ค้างจ่าย"
// (due medications) list and the AR locator's target selection are built on.
// No database involved: Medication instances are constructed in memory and
// never saved. "Now" is frozen with Carbon::setTestNow() for determinism.
class MedicationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeMedication(?string $timeSlot, ?Carbon $dispensedAt = null): Medication
    {
        return new Medication([
            'drug_name' => 'Paracetamol',
            'time_slot' => $timeSlot,
            'dispensed_at' => $dispensedAt,
        ]);
    }

    // ---- matchedDueTime ----

    /** @test */
    public function matched_due_time_is_null_when_the_medication_has_no_scheduled_time_slot()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));
        $medication = $this->makeMedication(null);

        $this->assertNull($medication->matchedDueTime());
    }

    /** @test */
    public function matched_due_time_is_null_when_every_scheduled_time_is_still_ahead_today()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 06:00:00'));
        $medication = $this->makeMedication('08:00,12:00,18:00');

        $this->assertNull($medication->matchedDueTime());
    }

    /** @test */
    public function matched_due_time_returns_the_first_slot_at_or_before_now()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 13:00:00'));
        $medication = $this->makeMedication('08:00,12:00,18:00');

        $this->assertSame('08:00', $medication->matchedDueTime());
    }

    /** @test */
    public function matched_due_time_matches_exactly_at_the_scheduled_minute()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));
        $medication = $this->makeMedication('08:00,12:00,18:00');

        $this->assertSame('08:00', $medication->matchedDueTime());
    }

    // ---- isDispensedToday ----

    /** @test */
    public function is_dispensed_today_is_false_when_never_dispensed()
    {
        $medication = $this->makeMedication('08:00', null);

        $this->assertFalse($medication->isDispensedToday());
    }

    /** @test */
    public function is_dispensed_today_is_true_when_dispensed_earlier_today()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 15:00:00'));
        $medication = $this->makeMedication('08:00', Carbon::parse('2026-08-14 08:05:00'));

        $this->assertTrue($medication->isDispensedToday());
    }

    /** @test */
    public function is_dispensed_today_is_false_when_last_dispensed_yesterday()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00'));
        $medication = $this->makeMedication('08:00', Carbon::parse('2026-08-13 08:05:00'));

        $this->assertFalse($medication->isDispensedToday());
    }

    // ---- isDueNow ----

    /** @test */
    public function is_due_now_is_true_when_not_dispensed_and_a_scheduled_time_has_passed()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00'));
        $medication = $this->makeMedication('08:00', null);

        $this->assertTrue($medication->isDueNow());
    }

    /** @test */
    public function is_due_now_is_false_once_already_dispensed_today_even_if_a_time_has_passed()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00'));
        $medication = $this->makeMedication('08:00', Carbon::parse('2026-08-14 08:05:00'));

        $this->assertFalse($medication->isDueNow());
    }

    /** @test */
    public function is_due_now_is_false_when_not_dispensed_but_no_scheduled_time_has_arrived_yet()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 06:00:00'));
        $medication = $this->makeMedication('08:00,12:00', null);

        $this->assertFalse($medication->isDueNow());
    }

    /** @test */
    public function is_due_now_is_false_when_there_is_no_schedule_at_all()
    {
        $medication = $this->makeMedication(null, null);

        $this->assertFalse($medication->isDueNow());
    }
}
