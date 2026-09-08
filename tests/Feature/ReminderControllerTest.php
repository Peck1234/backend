<?php

namespace Tests\Feature;

use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReminderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makePatient(): Patient
    {
        return Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'ward' => 'A', 'bed_no' => '1', 'qr_code_patient' => 'PATIENT-001']);
    }

    // ReminderController reads time_slot straight off the medication row and
    // doesn't care which meal it's in, but patient_meal_cassette_id is
    // NOT NULL now - every medication needs *some* cassette to satisfy it.
    private function makeCassette(Patient $patient, string $meal = 'breakfast_before'): PatientMealCassette
    {
        return PatientMealCassette::create([
            'patient_id' => $patient->id,
            'meal' => $meal,
            'qr_code' => "CASSETTE-{$patient->id}-{$meal}",
        ]);
    }

    /** @test */
    public function due_lists_only_medications_that_are_due_and_not_yet_dispensed()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 13:00:00'));
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);

        $due = Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Due Drug',
            'dose' => '1 เม็ด',
            'qr_code_cassette' => 'CASSETTE-DUE',
            'time_slot' => '08:00',
        ]);
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Not Due Yet',
            'dose' => '1 เม็ด',
            'qr_code_cassette' => 'CASSETTE-NOTYET',
            'time_slot' => '18:00',
        ]);
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Already Dispensed',
            'dose' => '1 เม็ด',
            'qr_code_cassette' => 'CASSETTE-DONE',
            'time_slot' => '08:00',
            'dispensed_at' => Carbon::parse('2026-08-14 08:05:00'),
        ]);

        $response = $this->getJson('/api/due-medications');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertCount(1, $body);
        $this->assertSame($due->id, $body[0]['order_id']);
        $this->assertSame('08:00', $body[0]['matched_time']);
        $this->assertSame($patient->id, $body[0]['patient_id']);
    }

    /** @test */
    public function due_returns_an_empty_list_when_nothing_is_due()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 06:00:00'));
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Later Drug',
            'dose' => '1 เม็ด',
            'qr_code_cassette' => 'CASSETTE-1',
            'time_slot' => '08:00',
        ]);

        $response = $this->getJson('/api/due-medications');

        $response->assertStatus(200)->assertExactJson([]);
    }

    /** @test */
    public function schedule_times_collects_unique_sorted_clean_hh_mm_slots_across_pending_medications()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 00:00:00'));
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);

        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'A',
            'dose' => '1 เม็ด',
            'qr_code_cassette' => 'CASSETTE-A',
            'time_slot' => '18:00,08:00',
        ]);
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'B',
            'dose' => '1 เม็ด',
            'qr_code_cassette' => 'CASSETTE-B',
            // duplicate of one slot above, plus a PRN note that isn't a clean HH:MM
            'time_slot' => '08:00,เมื่อมีไข้ ทุก 4-6 ชั่วโมง',
        ]);

        $response = $this->getJson('/api/reminder-times');

        $response->assertStatus(200)->assertExactJson(['times' => ['08:00', '18:00']]);
    }

    /** @test */
    public function schedule_times_excludes_medications_already_dispensed_today()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);

        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Dispensed',
            'dose' => '1 เม็ด',
            'qr_code_cassette' => 'CASSETTE-DONE',
            'time_slot' => '09:00',
            'dispensed_at' => Carbon::parse('2026-08-14 09:05:00'),
        ]);

        $response = $this->getJson('/api/reminder-times');

        $response->assertStatus(200)->assertExactJson(['times' => []]);
    }
}
