<?php

namespace Tests\Feature;

use App\Models\CartSlot;
use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use App\Models\SlotAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCassette(Patient $patient, string $meal = 'breakfast'): PatientMealCassette
    {
        return PatientMealCassette::create([
            'patient_id' => $patient->id,
            'meal' => $meal,
            'qr_code' => "CASSETTE-{$patient->id}-{$meal}",
        ]);
    }

    private function makeNurse(): Nurse
    {
        return Nurse::create([
            'full_name' => 'พยาบาล ทดสอบ',
            'username' => 'nurse1',
            'password' => Hash::make('secret123'),
            'qr_code_nurse' => 'NURSE-001',
        ]);
    }

    /** @test */
    public function index_lists_patients_with_slot_info_derived_from_cart_slots()
    {
        $patient = Patient::create([
            'full_name' => 'ผู้ป่วย ทดสอบ',
            'ward' => 'A',
            'bed_no' => '1',
            'qr_code_patient' => 'PATIENT-001',
        ]);
        CartSlot::create([
            'slot_code' => 'SLOT-0001',
            'slot_no' => 3,
            'status' => 'occupied',
            'current_patient_id' => $patient->id,
            'version' => 1,
        ]);

        $response = $this->getJson('/api/patients');

        $response->assertStatus(200)->assertJsonFragment([
            'patient_id' => $patient->id,
            'slot_no' => 3,
            'qr_code_slot' => 'SLOT-0001',
        ]);
    }

    /** @test */
    public function index_returns_null_slot_fields_for_a_patient_with_no_slot()
    {
        Patient::create([
            'full_name' => 'ผู้ป่วย ไม่มีช่อง',
            'qr_code_patient' => 'PATIENT-002',
        ]);

        $response = $this->getJson('/api/patients');

        $response->assertStatus(200)->assertJsonFragment([
            'slot_no' => null,
            'qr_code_slot' => null,
        ]);
    }

    /** @test */
    public function medications_are_sorted_by_earliest_time_slot_and_include_due_status()
    {
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);
        $evening = $this->makeCassette($patient, 'dinner');
        $morning = $this->makeCassette($patient, 'breakfast');
        $prn = $this->makeCassette($patient, 'prn');
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $evening->id,
            'drug_name' => 'Evening Drug',
            'dose' => '1 เม็ด',
            'time_slot' => '18:00',
        ]);
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $morning->id,
            'drug_name' => 'Morning Drug',
            'dose' => '1 เม็ด',
            'time_slot' => '08:00',
        ]);
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $prn->id,
            'drug_name' => 'PRN Drug',
            'dose' => '1 เม็ด',
            'time_slot' => null,
        ]);

        $response = $this->getJson("/api/patients/{$patient->id}/medications");

        $response->assertStatus(200);
        $names = collect($response->json('medications'))->pluck('drug_name')->all();
        $this->assertSame(['Morning Drug', 'Evening Drug', 'PRN Drug'], $names);
    }

    /** @test */
    public function store_creates_a_patient_with_medications_in_one_request()
    {
        $response = $this->postJson('/api/patients', [
            'full_name' => 'ผู้ป่วย ใหม่',
            'ward' => 'B',
            'bed_no' => '2',
            'qr_code_patient' => 'PATIENT-NEW',
            'medications' => [
                ['drug_name' => 'Paracetamol', 'dose' => '1 เม็ด', 'meal' => 'breakfast'],
            ],
        ]);

        $response->assertStatus(201)->assertJsonStructure(['patient_id']);
        $this->assertDatabaseHas('patients', ['qr_code_patient' => 'PATIENT-NEW']);
        $this->assertDatabaseHas('medications', ['drug_name' => 'Paracetamol', 'time_slot' => '08:00']);

        $patientId = $response->json('patient_id');
        $cassette = PatientMealCassette::where('patient_id', $patientId)->where('meal', 'breakfast')->first();
        $this->assertNotNull($cassette, 'a breakfast cassette should have been get-or-created');
        $this->assertSame("CASSETTE-{$patientId}-breakfast", $cassette->qr_code);
    }

    /** @test */
    public function store_rejects_a_duplicate_patient_qr_code()
    {
        // Caught by the 'unique:patients,qr_code_patient' validation rule
        // before the controller's transaction even runs - the QueryException
        // catch in store() exists only for the race-condition case where two
        // requests both pass validation before either commits.
        Patient::create(['full_name' => 'มีอยู่แล้ว', 'qr_code_patient' => 'PATIENT-DUP']);

        $response = $this->postJson('/api/patients', [
            'full_name' => 'ผู้ป่วย ใหม่',
            'qr_code_patient' => 'PATIENT-DUP',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('qr_code_patient');
    }

    /** @test */
    public function store_rejects_a_missing_required_field()
    {
        $response = $this->postJson('/api/patients', ['ward' => 'A']);

        $response->assertStatus(422)->assertJsonValidationErrors(['full_name', 'qr_code_patient']);
    }

    /** @test */
    public function update_replaces_the_patients_medication_list_keeping_ids_that_are_still_present()
    {
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);
        $breakfast = $this->makeCassette($patient, 'breakfast');
        $keep = Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $breakfast->id,
            'drug_name' => 'Keep Me',
            'dose' => '1 เม็ด',
        ]);
        $drop = Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $breakfast->id,
            'drug_name' => 'Drop Me',
            'dose' => '1 เม็ด',
        ]);

        $response = $this->putJson("/api/patients/{$patient->id}", [
            'full_name' => 'ผู้ป่วย ทดสอบ',
            'qr_code_patient' => 'PATIENT-001',
            'medications' => [
                ['id' => $keep->id, 'drug_name' => 'Keep Me Updated', 'dose' => '2 เม็ด', 'meal' => 'breakfast'],
                ['drug_name' => 'New Drug', 'dose' => '1 เม็ด', 'meal' => 'lunch'],
            ],
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('medications', ['id' => $keep->id, 'drug_name' => 'Keep Me Updated']);
        $this->assertDatabaseMissing('medications', ['id' => $drop->id]);
        $newDrug = Medication::where('drug_name', 'New Drug')->first();
        $this->assertSame('lunch', $newDrug->mealCassette->meal);
    }

    /** @test */
    public function update_moves_an_existing_medication_to_a_different_meal_cassette()
    {
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);
        $breakfast = $this->makeCassette($patient, 'breakfast');
        $medication = Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $breakfast->id,
            'drug_name' => 'Moved Drug',
            'dose' => '1 เม็ด',
        ]);

        $response = $this->putJson("/api/patients/{$patient->id}", [
            'full_name' => 'ผู้ป่วย ทดสอบ',
            'qr_code_patient' => 'PATIENT-001',
            'medications' => [
                ['id' => $medication->id, 'drug_name' => 'Moved Drug', 'dose' => '1 เม็ด', 'meal' => 'bedtime'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame('bedtime', $medication->fresh()->mealCassette->meal);
        $this->assertSame('21:00', $medication->fresh()->time_slot);
    }

    /** @test */
    public function update_rejects_a_qr_code_already_used_by_another_patient()
    {
        Patient::create(['full_name' => 'คนอื่น', 'qr_code_patient' => 'PATIENT-OTHER']);
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);

        $response = $this->putJson("/api/patients/{$patient->id}", [
            'full_name' => 'ผู้ป่วย ทดสอบ',
            'qr_code_patient' => 'PATIENT-OTHER',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('qr_code_patient');
    }

    /** @test */
    public function update_allows_keeping_the_patients_own_existing_qr_code()
    {
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);

        $response = $this->putJson("/api/patients/{$patient->id}", [
            'full_name' => 'ผู้ป่วย ทดสอบ (แก้ชื่อ)',
            'qr_code_patient' => 'PATIENT-001',
        ]);

        $response->assertStatus(200);
        $this->assertSame('ผู้ป่วย ทดสอบ (แก้ชื่อ)', $patient->fresh()->full_name);
    }

    // ---- destroy ----

    /** @test */
    public function destroy_requires_authentication()
    {
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);

        $response = $this->deleteJson("/api/patients/{$patient->id}");

        $response->assertStatus(401);
        $this->assertNotSoftDeleted('patients', ['id' => $patient->id]);
    }

    /** @test */
    public function destroy_soft_deletes_a_patient_with_no_slot()
    {
        Sanctum::actingAs($this->makeNurse());
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);

        $response = $this->deleteJson("/api/patients/{$patient->id}");

        $response->assertStatus(200)->assertJson(['ok' => true, 'slot_cleared' => false]);
        $this->assertSoftDeleted('patients', ['id' => $patient->id]);
    }

    /** @test */
    public function destroy_clears_the_patients_cart_slot_first()
    {
        Sanctum::actingAs($this->makeNurse());
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);
        $slot = CartSlot::create([
            'slot_code' => 'SLOT-0001',
            'status' => 'occupied',
            'current_patient_id' => $patient->id,
            'version' => 1,
            'occupied_at' => now(),
        ]);

        $response = $this->deleteJson("/api/patients/{$patient->id}");

        $response->assertStatus(200)->assertJson(['ok' => true, 'slot_cleared' => true]);
        $this->assertSoftDeleted('patients', ['id' => $patient->id]);
        $slot->refresh();
        $this->assertSame('empty', $slot->status);
        $this->assertNull($slot->current_patient_id);
        $this->assertSame(2, $slot->version);
    }

    /** @test */
    public function destroy_logs_the_slot_clear_as_caused_by_patient_deletion()
    {
        $nurse = $this->makeNurse();
        Sanctum::actingAs($nurse);
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);
        $slot = CartSlot::create([
            'slot_code' => 'SLOT-0001',
            'status' => 'occupied',
            'current_patient_id' => $patient->id,
            'version' => 1,
        ]);

        $this->deleteJson("/api/patients/{$patient->id}");

        $log = SlotAuditLog::where('slot_id', $slot->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('discharge', $log->action);
        $this->assertSame($nurse->id, $log->staff_id);
        $this->assertSame('patient_deleted', $log->after['reason']);
    }

    /** @test */
    public function destroy_keeps_the_patients_medications_and_dispense_history_in_the_database()
    {
        Sanctum::actingAs($this->makeNurse());
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);
        $cassette = $this->makeCassette($patient);
        $medication = Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Paracetamol',
            'dose' => '1 เม็ด',
        ]);

        $this->deleteJson("/api/patients/{$patient->id}");

        $this->assertDatabaseHas('medications', ['id' => $medication->id, 'patient_id' => $patient->id]);
        $this->assertDatabaseHas('patient_meal_cassettes', ['id' => $cassette->id]);
    }

    /** @test */
    public function destroy_removes_the_patient_from_the_index_listing()
    {
        Sanctum::actingAs($this->makeNurse());
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);

        $this->deleteJson("/api/patients/{$patient->id}");
        $response = $this->getJson('/api/patients');

        $response->assertStatus(200);
        $response->assertJsonMissing(['patient_id' => $patient->id]);
    }

    /** @test */
    public function destroy_404s_for_an_already_deleted_patient()
    {
        Sanctum::actingAs($this->makeNurse());
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => 'PATIENT-001']);
        $patient->delete();

        $response = $this->deleteJson("/api/patients/{$patient->id}");

        $response->assertStatus(404);
    }
}
