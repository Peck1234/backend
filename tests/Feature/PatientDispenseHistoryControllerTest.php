<?php

namespace Tests\Feature;

use App\Models\DispenseLog;
use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PatientDispenseHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private Nurse $nurse;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-10-15 10:00 in Thailand
        Carbon::setTestNow(Carbon::parse('2026-10-15 03:00:00', 'UTC'));

        $this->nurse = Nurse::create([
            'full_name' => 'สุดา ใจดี', 'username' => 'suda', 'email' => 'suda@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);
        $this->patient = Patient::create([
            'full_name' => 'สมชาย ใจงาม', 'ward' => 'อายุรกรรมชาย', 'bed_no' => '12', 'qr_code_patient' => 'PATIENT-001',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function medication(string $drug, string $meal, string $dose = '1 เม็ด'): Medication
    {
        $cassette = PatientMealCassette::getOrCreateFor($this->patient->id, $meal);

        return Medication::create([
            'patient_id' => $this->patient->id, 'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => $drug, 'dose' => $dose, 'qr_code_cassette' => $cassette->qr_code,
        ]);
    }

    private function dispensed(Medication $m, string $utc, string $result = 'correct'): DispenseLog
    {
        $log = DispenseLog::create([
            'medication_id' => $m->id, 'patient_id' => $this->patient->id, 'nurse_id' => $this->nurse->id,
            'cassette_qr' => 'X', 'result' => $result, 'message' => '',
        ]);
        $log->created_at = Carbon::parse($utc, 'UTC');
        $log->save();

        return $log;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->nurse->createToken('test')->plainTextToken];
    }

    /** @test */
    public function history_requires_login()
    {
        $this->getJson("/api/patients/{$this->patient->id}/dispense-history")->assertStatus(401);
        $this->getJson("/api/patients/{$this->patient->id}/dispense-history/export")->assertStatus(401);
    }

    /** @test */
    public function history_lists_only_this_patients_real_dispenses_in_thai_time_newest_first()
    {
        $losartan = $this->medication('Losartan', 'breakfast_after', '50 mg');
        $older = $this->dispensed($losartan, '2026-10-14 01:30:00');   // 14 ต.ค. 08:30 Thai
        $newer = $this->dispensed($losartan, '2026-10-15 01:10:00');   // 15 ต.ค. 08:10 Thai
        $this->dispensed($losartan, '2026-10-15 01:20:00', 'incorrect');
        DispenseLog::create(['patient_id' => $this->patient->id, 'cassette_qr' => 'X', 'result' => 'correct', 'message' => 'scan only']);

        $other = Patient::create(['full_name' => 'คนอื่น', 'qr_code_patient' => 'PATIENT-002']);
        $otherCassette = PatientMealCassette::getOrCreateFor($other->id, 'breakfast_after');
        $otherMed = Medication::create([
            'patient_id' => $other->id, 'patient_meal_cassette_id' => $otherCassette->id,
            'drug_name' => 'Other', 'dose' => '1', 'qr_code_cassette' => $otherCassette->qr_code,
        ]);
        DispenseLog::create(['medication_id' => $otherMed->id, 'patient_id' => $other->id, 'cassette_qr' => 'Y', 'result' => 'correct', 'message' => '']);

        $response = $this->withHeaders($this->auth())->getJson("/api/patients/{$this->patient->id}/dispense-history");

        $response->assertOk()
            ->assertJsonPath('from', '2026-10-09')
            ->assertJsonPath('to', '2026-10-15')
            ->assertJsonCount(2, 'dispenses')
            ->assertJsonPath('dispenses.0.id', $newer->id)
            ->assertJsonPath('dispenses.0.date', '2026-10-15')
            ->assertJsonPath('dispenses.0.time', '08:10')
            ->assertJsonPath('dispenses.0.drug_name', 'Losartan')
            ->assertJsonPath('dispenses.0.meal_label', 'เช้า หลังอาหาร')
            ->assertJsonPath('dispenses.0.nurse_name', 'สุดา ใจดี')
            ->assertJsonPath('dispenses.1.id', $older->id);
    }

    /** @test */
    public function history_uses_the_thai_calendar_day_for_range_edges()
    {
        $m = $this->medication('Losartan', 'bedtime_after');
        $this->dispensed($m, '2026-10-12 17:30:00'); // 13 ต.ค. 00:30 Thai -> inside a range starting the 13th
        $this->dispensed($m, '2026-10-12 16:30:00'); // 12 ต.ค. 23:30 Thai -> outside

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/patients/{$this->patient->id}/dispense-history?from=2026-10-13&to=2026-10-13");

        $response->assertOk()->assertJsonCount(1, 'dispenses')->assertJsonPath('dispenses.0.time', '00:30');
    }

    /** @test */
    public function history_rejects_a_reversed_or_too_long_range()
    {
        $url = "/api/patients/{$this->patient->id}/dispense-history";

        $this->withHeaders($this->auth())->getJson("$url?from=2026-10-15&to=2026-10-01")->assertStatus(422);
        $this->withHeaders($this->auth())->getJson("$url?from=2026-08-01&to=2026-10-15")->assertStatus(422);
        $this->withHeaders($this->auth())->getJson("$url?from=15/10/2026")->assertStatus(422);
    }

    /** @test */
    public function export_builds_the_medication_record_workbook_like_the_paper_form()
    {
        $morning = $this->medication('Losartan', 'breakfast_after', '50 mg');
        $evening = $this->medication('Losartan', 'dinner_after', '50 mg');
        $this->medication('Levodopa', 'lunch_before', '250 mg');
        $this->dispensed($morning, '2026-10-15 01:12:00'); // 08:12 Thai on the 15th

        $response = $this->withHeaders($this->auth())
            ->get("/api/patients/{$this->patient->id}/dispense-history/export?from=2026-10-14&to=2026-10-15");

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('2026-10-14_2026-10-15', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'mar') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);

        $record = $book->getSheet(0);
        $this->assertSame('บันทึกการให้ยา', $record->getTitle());
        $this->assertStringContainsString('สมชาย ใจงาม', $record->getCell('A2')->getValue());
        $this->assertStringContainsString('เตียง: 12', $record->getCell('A2')->getValue());
        // Day headers: 14 then 15 ต.ค., each spanning เช้า กลางวัน เย็น ก่อนนอน
        $this->assertSame('14 ต.ค. 69', $record->getCell('C3')->getValue());
        $this->assertSame('15 ต.ค. 69', $record->getCell('G3')->getValue());
        $this->assertSame('เช้า', $record->getCell('C4')->getValue());
        $this->assertSame('ก่อนนอน', $record->getCell('F4')->getValue());

        // Row 5: Losartan (breakfast + dinner merged into one row) - meals sort breakfast first
        $this->assertSame('Losartan 50 mg', $record->getCell('B5')->getValue()); // drug only, no meal line
        $this->assertSame('หลังอาหาร', $record->getCell('C5')->getValue());      // 14th morning, not given
        $this->assertSame('-', $record->getCell('D5')->getValue());              // 14th noon, not scheduled
        $this->assertSame('หลังอาหาร', $record->getCell('E5')->getValue());      // 14th evening
        $this->assertSame('หลังอาหาร ✓ 08:12', $record->getCell('G5')->getValue()); // 15th morning, given

        // Row 6: Levodopa, lunch before food
        $this->assertStringContainsString('Levodopa 250 mg', $record->getCell('B6')->getValue());
        $this->assertSame('ก่อนอาหาร', $record->getCell('D6')->getValue());

        $this->assertSame(2, $book->getSheetCount());
        $history = $book->getSheet(1);
        $this->assertSame('ประวัติการจ่ายยา', $history->getTitle());
        $this->assertSame('15 ต.ค. 69', $history->getCell('A2')->getValue());
        $this->assertSame('08:12', $history->getCell('B2')->getValue());
        $this->assertSame('Losartan', $history->getCell('C2')->getValue());
        $this->assertSame('สุดา ใจดี', $history->getCell('F2')->getValue());
    }
}
