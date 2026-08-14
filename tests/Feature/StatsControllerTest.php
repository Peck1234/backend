<?php

namespace Tests\Feature;

use App\Models\DispenseLog;
use App\Models\Medication;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makePatient(string $ward, string $qr): Patient
    {
        return Patient::create(['full_name' => 'ผู้ป่วย ' . $qr, 'ward' => $ward, 'qr_code_patient' => $qr]);
    }

    /** @test */
    public function index_reports_totals_dispensed_rate_and_breakdowns_by_slot_ward_and_drug()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $wardA = $this->makePatient('A', 'PATIENT-A');
        $wardB = $this->makePatient('B', 'PATIENT-B');

        Medication::create([
            'patient_id' => $wardA->id, 'drug_name' => 'Paracetamol', 'dose' => '1',
            'qr_code_cassette' => 'C1', 'time_slot' => '08:00',
            'dispensed_at' => Carbon::parse('2026-08-14 08:05:00'),
        ]);
        Medication::create([
            'patient_id' => $wardA->id, 'drug_name' => 'Paracetamol', 'dose' => '1',
            'qr_code_cassette' => 'C2', 'time_slot' => '08:00',
        ]);
        Medication::create([
            'patient_id' => $wardB->id, 'drug_name' => 'Amoxicillin', 'dose' => '1',
            'qr_code_cassette' => 'C3', 'time_slot' => '12:00',
        ]);

        $response = $this->getJson('/api/dispense-stats');

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame(3, $body['total']);
        $this->assertSame(1, $body['dispensed']);
        $this->assertSame(33.3, $body['dispensed_rate']);

        $slot0800 = collect($body['by_time_slot'])->firstWhere('slot', '08:00');
        $this->assertSame(2, $slot0800['total']);
        $this->assertSame(1, $slot0800['dispensed']);

        $wardAStats = collect($body['by_ward'])->firstWhere('ward', 'A');
        $this->assertSame(2, $wardAStats['total']);

        $drugStats = collect($body['by_drug'])->firstWhere('drug_name', 'Paracetamol');
        $this->assertSame(2, $drugStats['total']);
        $this->assertSame(1, $drugStats['dispensed']);
    }

    /** @test */
    public function index_reports_zero_dispensed_rate_when_there_are_no_medications()
    {
        $response = $this->getJson('/api/dispense-stats');

        $response->assertStatus(200)->assertJson([
            'total' => 0,
            'dispensed' => 0,
            'dispensed_rate' => 0,
        ]);
    }

    /** @test */
    public function index_trend_counts_only_correct_dispenses_grouped_by_day()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $log = DispenseLog::create([
            'cassette_qr' => 'C1', 'result' => 'correct', 'message' => 'จ่ายยาสำเร็จ',
        ]);
        $log->created_at = Carbon::parse('2026-08-14 09:00:00');
        $log->save();

        $rejected = DispenseLog::create([
            'cassette_qr' => 'C2', 'result' => 'incorrect', 'message' => 'QR code ไม่ถูกต้อง',
        ]);
        $rejected->created_at = Carbon::parse('2026-08-14 09:00:00');
        $rejected->save();

        $response = $this->getJson('/api/dispense-stats');

        $todayTrend = collect($response->json('trend'))->firstWhere('date', '2026-08-14');
        $this->assertSame(1, $todayTrend['dispensed']);
    }
}
