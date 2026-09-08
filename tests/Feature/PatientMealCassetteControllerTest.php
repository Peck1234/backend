<?php

namespace Tests\Feature;

use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientMealCassetteControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePatient(string $qr = 'PATIENT-001'): Patient
    {
        return Patient::create([
            'full_name' => 'ผู้ป่วย ทดสอบ',
            'ward' => 'A',
            'bed_no' => '1',
            'qr_code_patient' => $qr,
        ]);
    }

    private function makeCassette(Patient $patient, string $meal = 'breakfast_before'): PatientMealCassette
    {
        return PatientMealCassette::create([
            'patient_id' => $patient->id,
            'meal' => $meal,
            'qr_code' => "CASSETTE-{$patient->id}-{$meal}",
        ]);
    }

    private function makeMedication(PatientMealCassette $cassette, string $drugName = 'Paracetamol'): Medication
    {
        return Medication::create([
            'patient_id' => $cassette->patient_id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => $drugName,
            'dose' => '1 เม็ด',
        ]);
    }

    // ---- assignMedicine ----
    //
    // No test previously covered this endpoint at all (confirmed by grepping
    // the whole test suite for 'assign-medicine' before writing these) even
    // though it's the core new catalog-driven flow. Added alongside the
    // 2026-09-02 meal expansion (5 -> 9 values, splitting each real meal into
    // before/after food) specifically to prove that split behaves correctly,
    // since that's the one thing that's genuinely new/risky here - the
    // get-or-create + validation machinery itself was already covered
    // indirectly by other tests using meal fixtures.

    /** @test */
    public function assign_medicine_treats_before_and_after_food_as_two_separate_cassettes()
    {
        $patient = $this->makePatient();

        $before = $this->postJson("/api/patients/{$patient->id}/meal-cassettes/assign-medicine", [
            'meal' => 'breakfast_before',
            'drug_name' => 'Metformin',
            'dose' => '1 tab',
        ]);
        $after = $this->postJson("/api/patients/{$patient->id}/meal-cassettes/assign-medicine", [
            'meal' => 'breakfast_after',
            'drug_name' => 'Paracetamol',
            'dose' => '1 tab',
        ]);

        $before->assertStatus(201)->assertJson([
            'meal' => 'breakfast_before',
            'qr_code' => "CASSETTE-{$patient->id}-breakfast_before",
            'is_new' => true,
        ]);
        $after->assertStatus(201)->assertJson([
            'meal' => 'breakfast_after',
            'qr_code' => "CASSETTE-{$patient->id}-breakfast_after",
            'is_new' => true,
        ]);

        // Two distinct cassette rows, not one shared "breakfast" cassette -
        // the whole point of the before/after split.
        $this->assertNotSame($before->json('cassette_id'), $after->json('cassette_id'));
        $this->assertSame(2, PatientMealCassette::where('patient_id', $patient->id)->count());
    }

    /** @test */
    public function assign_medicine_reuses_the_same_cassette_for_a_second_drug_in_the_same_before_after_slot()
    {
        $patient = $this->makePatient();

        $first = $this->postJson("/api/patients/{$patient->id}/meal-cassettes/assign-medicine", [
            'meal' => 'dinner_after', 'drug_name' => 'Metformin', 'dose' => '1 tab',
        ]);
        $second = $this->postJson("/api/patients/{$patient->id}/meal-cassettes/assign-medicine", [
            'meal' => 'dinner_after', 'drug_name' => 'Simvastatin', 'dose' => '1 tab',
        ]);

        $second->assertStatus(201)->assertJson(['is_new' => false]);
        $this->assertSame($first->json('cassette_id'), $second->json('cassette_id'));
        $this->assertSame(1, PatientMealCassette::where('patient_id', $patient->id)->count());
        $this->assertCount(2, $second->json('medications'));
    }

    /** @test */
    public function assign_medicine_rejects_a_pre_expansion_meal_value_that_no_longer_exists()
    {
        $patient = $this->makePatient();

        // 'breakfast' (undifferentiated) was replaced by 'breakfast_before'/
        // 'breakfast_after' in the 2026-09-02 enum expansion - it must no
        // longer validate.
        $response = $this->postJson("/api/patients/{$patient->id}/meal-cassettes/assign-medicine", [
            'meal' => 'breakfast', 'drug_name' => 'Paracetamol', 'dose' => '1 tab',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['meal']);
    }

    /** @test */
    public function assign_medicine_accepts_prn_unchanged_by_the_before_after_split()
    {
        $patient = $this->makePatient();

        $response = $this->postJson("/api/patients/{$patient->id}/meal-cassettes/assign-medicine", [
            'meal' => 'prn', 'drug_name' => 'Ibuprofen', 'dose' => '1 tab',
        ]);

        $response->assertStatus(201)->assertJson([
            'meal' => 'prn',
            'qr_code' => "CASSETTE-{$patient->id}-prn",
            'is_new' => true,
        ]);
    }

    // ---- show ----

    /** @test */
    public function show_returns_the_cassette_with_its_medications()
    {
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $med = $this->makeMedication($cassette, 'Paracetamol');

        $response = $this->getJson("/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}");

        $response->assertStatus(200)->assertJson([
            'cassette_id' => $cassette->id,
            'meal' => 'breakfast_before',
            'qr_code' => $cassette->qr_code,
            'medications' => [
                ['order_id' => $med->id, 'drug_name' => 'Paracetamol'],
            ],
        ]);
    }

    /** @test */
    public function show_404s_when_the_cassette_belongs_to_a_different_patient()
    {
        $patient = $this->makePatient('PATIENT-001');
        $otherPatient = $this->makePatient('PATIENT-002');
        $cassette = $this->makeCassette($otherPatient);

        $response = $this->getJson("/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}");

        $response->assertStatus(404);
    }

    // ---- updateMedicine ----

    /** @test */
    public function update_medicine_edits_the_drug_in_place()
    {
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $med = $this->makeMedication($cassette, 'Paracetamol');

        $response = $this->putJson(
            "/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}/medications/{$med->id}",
            [
                'drug_name' => 'Paracetamol 500',
                'standard_dose' => '500mg',
                'purpose' => 'ลดไข้',
                'dose' => '2 เม็ด',
                'instruction' => 'หลังอาหาร',
            ]
        );

        $response->assertStatus(200)->assertJson([
            'medications' => [
                ['order_id' => $med->id, 'drug_name' => 'Paracetamol 500', 'dose' => '2 เม็ด'],
            ],
        ]);
        $this->assertDatabaseHas('medications', [
            'id' => $med->id,
            'drug_name' => 'Paracetamol 500',
            'dose' => '2 เม็ด',
        ]);
    }

    /** @test */
    public function update_medicine_does_not_touch_other_medications_in_the_same_cassette()
    {
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $med = $this->makeMedication($cassette, 'Paracetamol');
        $other = $this->makeMedication($cassette, 'Amoxicillin');

        $response = $this->putJson(
            "/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}/medications/{$med->id}",
            ['drug_name' => 'Paracetamol 500', 'dose' => '1 เม็ด']
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('medications', ['id' => $other->id, 'drug_name' => 'Amoxicillin']);
    }

    /** @test */
    public function update_medicine_404s_when_the_medication_belongs_to_a_different_cassette()
    {
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient, 'breakfast_before');
        $otherCassette = $this->makeCassette($patient, 'lunch_before');
        $med = $this->makeMedication($otherCassette, 'Paracetamol');

        $response = $this->putJson(
            "/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}/medications/{$med->id}",
            ['drug_name' => 'Paracetamol 500', 'dose' => '1 เม็ด']
        );

        $response->assertStatus(404);
    }

    /** @test */
    public function update_medicine_validates_required_fields()
    {
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $med = $this->makeMedication($cassette);

        $response = $this->putJson(
            "/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}/medications/{$med->id}",
            ['drug_name' => '', 'dose' => '']
        );

        $response->assertStatus(422);
    }

    // ---- removeMedicine ----

    /** @test */
    public function remove_medicine_deletes_only_that_drug()
    {
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $med = $this->makeMedication($cassette, 'Paracetamol');
        $other = $this->makeMedication($cassette, 'Amoxicillin');

        $response = $this->deleteJson(
            "/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}/medications/{$med->id}"
        );

        $response->assertStatus(200)->assertJson([
            'medications' => [
                ['order_id' => $other->id, 'drug_name' => 'Amoxicillin'],
            ],
        ]);
        $this->assertDatabaseMissing('medications', ['id' => $med->id]);
        $this->assertDatabaseHas('medications', ['id' => $other->id]);
    }

    /** @test */
    public function remove_medicine_keeps_the_cassette_and_its_qr_even_when_it_becomes_empty()
    {
        $patient = $this->makePatient();
        $cassette = $this->makeCassette($patient);
        $med = $this->makeMedication($cassette, 'Paracetamol');

        $this->deleteJson(
            "/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}/medications/{$med->id}"
        );

        $this->assertDatabaseHas('patient_meal_cassettes', [
            'id' => $cassette->id,
            'qr_code' => $cassette->qr_code,
        ]);
    }

    /** @test */
    public function remove_medicine_404s_when_the_medication_belongs_to_a_different_patient()
    {
        $patient = $this->makePatient('PATIENT-001');
        $otherPatient = $this->makePatient('PATIENT-002');
        $cassette = $this->makeCassette($patient);
        $otherCassette = $this->makeCassette($otherPatient);
        $med = $this->makeMedication($otherCassette, 'Paracetamol');

        $response = $this->deleteJson(
            "/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}/medications/{$med->id}"
        );

        $response->assertStatus(404);
    }
}
