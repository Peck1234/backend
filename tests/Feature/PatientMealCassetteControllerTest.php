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

    private function makeCassette(Patient $patient, string $meal = 'breakfast'): PatientMealCassette
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
            'meal' => 'breakfast',
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
        $cassette = $this->makeCassette($patient, 'breakfast');
        $otherCassette = $this->makeCassette($patient, 'lunch');
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
