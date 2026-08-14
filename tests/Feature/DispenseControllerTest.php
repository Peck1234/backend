<?php

namespace Tests\Feature;

use App\Models\DispenseLog;
use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// HTTP-level coverage for POST /api/verify-dispense. DispenseVerificationServiceTest
// (Unit) already covers every branch of the decision logic in isolation; this
// exercises the full stack around it - the three QR lookups, the DispenseLog
// row it writes on every call, the dispensed_at side effect on success, and
// that DispenseController::respond() never leaks the internal
// should_dispense flag into the JSON response.
class DispenseControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeNurse(): Nurse
    {
        return Nurse::create([
            'full_name' => 'พยาบาล ทดสอบ',
            'username' => 'nurse1',
            'password' => Hash::make('secret123'),
            'qr_code_nurse' => 'NURSE-001',
        ]);
    }

    private function makePatient(string $qr = 'PATIENT-001'): Patient
    {
        return Patient::create([
            'full_name' => 'ผู้ป่วย ทดสอบ',
            'ward' => 'A',
            'bed_no' => '1',
            'qr_code_patient' => $qr,
        ]);
    }

    private function makeMedication(Patient $patient, string $qr = 'CASSETTE-001', ?string $dispensedAt = null): Medication
    {
        return Medication::create([
            'patient_id' => $patient->id,
            'drug_name' => 'Paracetamol',
            'dose' => '1 เม็ด',
            'qr_code_cassette' => $qr,
            'dispensed_at' => $dispensedAt,
        ]);
    }

    /** @test */
    public function a_correct_scan_dispenses_the_medication_and_only_returns_the_public_fields()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();
        $medication = $this->makeMedication($patient);

        $response = $this->postJson('/api/verify-dispense', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $medication->qr_code_cassette,
        ]);

        $response->assertStatus(200)
            ->assertExactJson([
                'result' => 'correct',
                'drug_name' => 'Paracetamol',
                'message' => 'จ่ายยาสำเร็จ',
            ]);

        $this->assertNotNull($medication->fresh()->dispensed_at);
        $this->assertDatabaseHas('dispense_logs', [
            'medication_id' => $medication->id,
            'patient_id' => $patient->id,
            'nurse_id' => $nurse->id,
            'result' => 'correct',
        ]);
    }

    /** @test */
    public function an_unknown_cassette_qr_is_rejected_without_dispensing_anything()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();

        $response = $this->postJson('/api/verify-dispense', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => 'CASSETTE-NOPE',
        ]);

        $response->assertStatus(200)->assertExactJson([
            'result' => 'incorrect',
            'drug_name' => null,
            'message' => 'QR code ไม่ถูกต้อง',
        ]);

        $this->assertDatabaseHas('dispense_logs', [
            'cassette_qr' => 'CASSETTE-NOPE',
            'result' => 'incorrect',
        ]);
    }

    /** @test */
    public function a_cassette_belonging_to_a_different_patient_is_rejected()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient('PATIENT-001');
        $otherPatient = $this->makePatient('PATIENT-002');
        $medication = $this->makeMedication($otherPatient);

        $response = $this->postJson('/api/verify-dispense', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $medication->qr_code_cassette,
        ]);

        $response->assertStatus(200)->assertExactJson([
            'result' => 'incorrect',
            'drug_name' => 'Paracetamol',
            'message' => 'ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้',
        ]);

        $this->assertNull($medication->fresh()->dispensed_at);
    }

    /** @test */
    public function a_medication_already_dispensed_today_is_rejected_on_a_second_scan()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();
        $medication = $this->makeMedication($patient, 'CASSETTE-001', now()->toDateTimeString());

        $response = $this->postJson('/api/verify-dispense', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $medication->qr_code_cassette,
        ]);

        $response->assertStatus(200)->assertExactJson([
            'result' => 'incorrect',
            'drug_name' => 'Paracetamol',
            'message' => 'ยานี้ถูกจ่ายไปแล้ววันนี้',
        ]);
    }

    /** @test */
    public function missing_fields_are_rejected_with_a_validation_error()
    {
        $response = $this->postJson('/api/verify-dispense', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nurse_qr', 'patient_qr', 'cassette_qr']);

        $this->assertSame(0, DispenseLog::count());
    }
}
