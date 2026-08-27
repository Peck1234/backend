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

// HTTP-level coverage for the two-step cassette dispense flow:
// POST /api/verify-cassette (scan -> get the whole meal's drug list) then
// POST /api/dispense-medications (confirm one or more of them). Replaces the
// old single-call-per-drug /api/verify-dispense now that one QR represents a
// whole meal rather than one medication.
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

    private function makeCassette(Patient $patient, string $meal = 'breakfast'): PatientMealCassette
    {
        return PatientMealCassette::create([
            'patient_id' => $patient->id,
            'meal' => $meal,
            'qr_code' => "CASSETTE-{$patient->id}-{$meal}",
        ]);
    }

    private function makeMedication(PatientMealCassette $cassette, string $drugName = 'Paracetamol', ?string $dispensedAt = null): Medication
    {
        return Medication::create([
            'patient_id' => $cassette->patient_id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => $drugName,
            'dose' => '1 เม็ด',
            'dispensed_at' => $dispensedAt,
        ]);
    }

    // ---- verify-cassette ----

    /** @test */
    public function verify_cassette_returns_every_medication_currently_in_the_meal()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $this->makeMedication($cassette, 'Paracetamol');
        $this->makeMedication($cassette, 'Amoxicillin');

        $response = $this->postJson('/api/verify-cassette', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $cassette->qr_code,
        ]);

        $response->assertStatus(200)->assertJson([
            'result' => 'correct',
            'meal' => 'breakfast',
        ]);
        $drugNames = collect($response->json('medications'))->pluck('drug_name')->all();
        $this->assertSame(['Paracetamol', 'Amoxicillin'], $drugNames);
        $this->assertDatabaseHas('dispense_logs', [
            'cassette_qr' => $cassette->qr_code,
            'result' => 'correct',
            'medication_id' => null,
        ]);
    }

    /** @test */
    public function verify_cassette_flags_which_medications_are_already_dispensed_today()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $this->makeMedication($cassette, 'Paracetamol', now()->toDateTimeString());
        $this->makeMedication($cassette, 'Amoxicillin');

        $response = $this->postJson('/api/verify-cassette', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $cassette->qr_code,
        ]);

        $meds = collect($response->json('medications'))->keyBy('drug_name');
        $this->assertTrue($meds['Paracetamol']['is_dispensed_today']);
        $this->assertFalse($meds['Amoxicillin']['is_dispensed_today']);
    }

    /** @test */
    public function verify_cassette_rejects_an_unknown_qr_without_dispensing_anything()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();

        $response = $this->postJson('/api/verify-cassette', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => 'CASSETTE-NOPE',
        ]);

        $response->assertStatus(200)->assertJson(['result' => 'incorrect', 'message' => 'QR code ไม่ถูกต้อง']);
        $this->assertDatabaseHas('dispense_logs', ['cassette_qr' => 'CASSETTE-NOPE', 'result' => 'incorrect']);
    }

    /** @test */
    public function verify_cassette_rejects_a_cassette_belonging_to_a_different_patient()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient('PATIENT-001');
        $otherPatient = $this->makePatient('PATIENT-002');
        $cassette = $this->makeCassette($otherPatient);

        $response = $this->postJson('/api/verify-cassette', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $cassette->qr_code,
        ]);

        $response->assertStatus(200)->assertJson([
            'result' => 'incorrect',
            'message' => 'ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้',
        ]);
    }

    /** @test */
    public function verify_cassette_rejects_missing_fields_with_a_validation_error()
    {
        $response = $this->postJson('/api/verify-cassette', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['nurse_qr', 'patient_qr', 'cassette_qr']);
        $this->assertSame(0, DispenseLog::count());
    }

    // ---- dispense-medications ----

    /** @test */
    public function dispense_medications_dispenses_only_the_requested_ids()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $paracetamol = $this->makeMedication($cassette, 'Paracetamol');
        $amoxicillin = $this->makeMedication($cassette, 'Amoxicillin');

        $response = $this->postJson('/api/dispense-medications', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $cassette->qr_code,
            'medication_ids' => [$paracetamol->id],
        ]);

        $response->assertStatus(200)->assertJson([
            'results' => [['order_id' => $paracetamol->id, 'result' => 'correct']],
        ]);
        $this->assertNotNull($paracetamol->fresh()->dispensed_at);
        $this->assertNull($amoxicillin->fresh()->dispensed_at);
        $this->assertDatabaseHas('dispense_logs', [
            'medication_id' => $paracetamol->id, 'result' => 'correct',
        ]);
    }

    /** @test */
    public function dispense_medications_confirms_the_whole_meal_in_one_call()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $paracetamol = $this->makeMedication($cassette, 'Paracetamol');
        $amoxicillin = $this->makeMedication($cassette, 'Amoxicillin');

        $response = $this->postJson('/api/dispense-medications', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $cassette->qr_code,
            'medication_ids' => [$paracetamol->id, $amoxicillin->id],
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($paracetamol->fresh()->dispensed_at);
        $this->assertNotNull($amoxicillin->fresh()->dispensed_at);
    }

    /** @test */
    public function dispense_medications_skips_one_already_dispensed_today_without_failing_the_rest()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $paracetamol = $this->makeMedication($cassette, 'Paracetamol', now()->toDateTimeString());
        $amoxicillin = $this->makeMedication($cassette, 'Amoxicillin');

        $response = $this->postJson('/api/dispense-medications', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $cassette->qr_code,
            'medication_ids' => [$paracetamol->id, $amoxicillin->id],
        ]);

        $results = collect($response->json('results'))->keyBy('order_id');
        $this->assertSame('incorrect', $results[$paracetamol->id]['result']);
        $this->assertSame('correct', $results[$amoxicillin->id]['result']);
        $this->assertNotNull($amoxicillin->fresh()->dispensed_at);
    }

    /** @test */
    public function dispense_medications_rejects_a_medication_id_that_is_not_in_this_cassette()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient, 'breakfast');
        $otherCassette = $this->makeCassette($patient, 'lunch');
        $foreignMedication = $this->makeMedication($otherCassette, 'Ibuprofen');

        $response = $this->postJson('/api/dispense-medications', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $cassette->qr_code,
            'medication_ids' => [$foreignMedication->id],
        ]);

        $results = collect($response->json('results'))->keyBy('order_id');
        $this->assertSame('incorrect', $results[$foreignMedication->id]['result']);
        $this->assertNull($foreignMedication->fresh()->dispensed_at);
    }

    /** @test */
    public function dispense_medications_rejects_a_cassette_belonging_to_a_different_patient()
    {
        $nurse = $this->makeNurse();
        $patient = $this->makePatient('PATIENT-001');
        $otherPatient = $this->makePatient('PATIENT-002');
        $cassette = $this->makeCassette($otherPatient);
        $medication = $this->makeMedication($cassette, 'Paracetamol');

        $response = $this->postJson('/api/dispense-medications', [
            'nurse_qr' => $nurse->qr_code_nurse,
            'patient_qr' => $patient->qr_code_patient,
            'cassette_qr' => $cassette->qr_code,
            'medication_ids' => [$medication->id],
        ]);

        $response->assertStatus(422)->assertJson(['detail' => 'ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้']);
        $this->assertNull($medication->fresh()->dispensed_at);
    }

    /** @test */
    public function dispense_medications_rejects_missing_fields_with_a_validation_error()
    {
        $response = $this->postJson('/api/dispense-medications', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nurse_qr', 'patient_qr', 'cassette_qr', 'medication_ids']);
    }
}
