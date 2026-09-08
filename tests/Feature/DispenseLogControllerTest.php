<?php

namespace Tests\Feature;

use App\Models\DispenseLog;
use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DispenseLogControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function index_returns_logs_newest_first_with_related_names_resolved()
    {
        $nurse = Nurse::create([
            'full_name' => 'พยาบาล ทดสอบ', 'username' => 'nurse1',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);
        $cassette = PatientMealCassette::create([
            'patient_id' => $patient->id, 'meal' => 'breakfast_before', 'qr_code' => "CASSETTE-{$patient->id}-breakfast_before",
        ]);
        $medication = Medication::create([
            'patient_id' => $patient->id, 'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Paracetamol', 'dose' => '1',
            'qr_code_cassette' => 'CASSETTE-1',
        ]);

        $older = DispenseLog::create([
            'medication_id' => $medication->id, 'patient_id' => $patient->id, 'nurse_id' => $nurse->id,
            'cassette_qr' => 'CASSETTE-1', 'result' => 'correct', 'message' => 'จ่ายยาสำเร็จ',
        ]);
        $older->created_at = now()->subMinute();
        $older->save();

        $newer = DispenseLog::create([
            'cassette_qr' => 'CASSETTE-UNKNOWN', 'result' => 'incorrect', 'message' => 'QR code ไม่ถูกต้อง',
        ]);

        $response = $this->getJson('/api/dispense-logs');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertCount(2, $body);
        $this->assertSame($newer->id, $body[0]['id']);
        $this->assertNull($body[0]['drug_name']);
        $this->assertSame($older->id, $body[1]['id']);
        $this->assertSame('Paracetamol', $body[1]['drug_name']);
        $this->assertSame('พยาบาล ทดสอบ', $body[1]['nurse_name']);
        $this->assertSame('ผู้ป่วย ทดสอบ', $body[1]['patient_name']);
    }

    /** @test */
    public function index_returns_an_empty_list_when_there_are_no_logs()
    {
        $response = $this->getJson('/api/dispense-logs');

        $response->assertStatus(200)->assertExactJson([]);
    }

    /** @test */
    public function index_caps_results_at_one_hundred()
    {
        for ($i = 0; $i < 105; $i++) {
            DispenseLog::create([
                'cassette_qr' => "CASSETTE-{$i}", 'result' => 'incorrect', 'message' => 'QR code ไม่ถูกต้อง',
            ]);
        }

        $response = $this->getJson('/api/dispense-logs');

        $this->assertCount(100, $response->json());
    }
}
