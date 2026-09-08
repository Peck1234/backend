<?php

namespace Tests\Feature;

use App\Models\Medication;
use App\Models\MedicineCatalog;
use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicineCatalogControllerTest extends TestCase
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

    private function makePatientOnDrug(string $drugName, string $qr = 'PATIENT-001'): Patient
    {
        $patient = Patient::create(['full_name' => 'ผู้ป่วย ทดสอบ', 'qr_code_patient' => $qr]);
        $cassette = PatientMealCassette::create([
            'patient_id' => $patient->id,
            'meal' => 'breakfast_before',
            'qr_code' => "CASSETTE-{$patient->id}-breakfast_before",
        ]);
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => $drugName,
            'dose' => '1 เม็ด',
        ]);

        return $patient;
    }

    /** @test */
    public function index_returns_all_medicines_with_favorites_sorted_first()
    {
        MedicineCatalog::create(['drug_name' => 'Zinc', 'is_favorite' => false]);
        MedicineCatalog::create(['drug_name' => 'Amoxicillin', 'is_favorite' => true]);
        MedicineCatalog::create(['drug_name' => 'Paracetamol', 'is_favorite' => false]);

        $response = $this->getJson('/api/medicine-catalog');

        $response->assertStatus(200);
        $names = collect($response->json('medicines'))->pluck('drug_name')->all();
        $this->assertSame(['Amoxicillin', 'Paracetamol', 'Zinc'], $names);
    }

    /** @test */
    public function index_search_matches_anywhere_in_the_name_not_just_the_start()
    {
        MedicineCatalog::create(['drug_name' => 'Ibuprofen 400mg']);
        MedicineCatalog::create(['drug_name' => 'Paracetamol 500mg']);

        $response = $this->getJson('/api/medicine-catalog?search=fen');

        $response->assertStatus(200);
        $names = collect($response->json('medicines'))->pluck('drug_name')->all();
        $this->assertSame(['Ibuprofen 400mg'], $names);
    }

    /** @test */
    public function index_search_also_matches_generic_name()
    {
        MedicineCatalog::create(['drug_name' => 'Tylenol', 'generic_name' => 'Paracetamol']);

        $response = $this->getJson('/api/medicine-catalog?search=paraceta');

        $response->assertStatus(200)->assertJsonFragment(['drug_name' => 'Tylenol']);
    }

    /** @test */
    public function store_creates_a_new_medicine()
    {
        $response = $this->postJson('/api/medicine-catalog', [
            'drug_name' => 'Amoxicillin 250mg',
            'standard_dose' => '250mg',
            'category' => 'ยาปฏิชีวนะ',
        ]);

        $response->assertStatus(201)->assertJsonFragment(['drug_name' => 'Amoxicillin 250mg']);
        $this->assertDatabaseHas('medicine_catalog', ['drug_name' => 'Amoxicillin 250mg']);
    }

    /** @test */
    public function store_rejects_an_exact_duplicate_drug_name()
    {
        MedicineCatalog::create(['drug_name' => 'Paracetamol 500mg']);

        $response = $this->postJson('/api/medicine-catalog', ['drug_name' => 'Paracetamol 500mg']);

        $response->assertStatus(422)->assertJsonValidationErrors('drug_name');
    }

    /** @test */
    public function favorite_toggles_the_flag_on_and_off()
    {
        $medicine = MedicineCatalog::create(['drug_name' => 'Paracetamol', 'is_favorite' => false]);

        $on = $this->putJson("/api/medicine-catalog/{$medicine->id}/favorite");
        $on->assertStatus(200)->assertJson(['is_favorite' => true]);

        $off = $this->putJson("/api/medicine-catalog/{$medicine->id}/favorite");
        $off->assertStatus(200)->assertJson(['is_favorite' => false]);
    }

    /** @test */
    public function bulk_import_inserts_valid_rows_and_reports_errors_for_invalid_ones_with_reasons()
    {
        MedicineCatalog::create(['drug_name' => 'Paracetamol 500mg']);

        $csv = "ชื่อยา,ชื่อสามัญ,ขนาดมาตรฐาน,หมวดหมู่,สรรพคุณ,ข้อห้ามใช้\n"
            . "Cetirizine 10mg,Cetirizine,10mg,ยาแก้แพ้,ลดอาการแพ้,ง่วงซึม\n"
            . ",Metformin,500mg,เบาหวาน,,\n"
            . "Amoxicillin 500mg,Amoxicillin,,ยาปฏิชีวนะ,,\n"
            . "Paracetamol 500mg,Paracetamol,500mg,,,\n"
            . "Omeprazole 20mg,Omeprazole,20mg,ยาลดกรด,,\n"
            . "Omeprazole 20mg,Omeprazole,20mg,ยาลดกรด,,\n";
        $file = UploadedFile::fake()->createWithContent('medicines.csv', $csv);

        $response = $this->postJson('/api/medicine-catalog/bulk-import', ['file' => $file]);

        $response->assertStatus(200)->assertJson([
            'imported_count' => 2,
            'failed_count' => 4,
        ]);
        $this->assertDatabaseHas('medicine_catalog', ['drug_name' => 'Cetirizine 10mg']);
        $this->assertDatabaseHas('medicine_catalog', ['drug_name' => 'Omeprazole 20mg']);
        $this->assertDatabaseCount('medicine_catalog', 3); // pre-existing Paracetamol + 2 newly imported

        $errors = collect($response->json('errors'));
        $this->assertSame(3, $errors->firstWhere('reason', 'ไม่ได้ระบุชื่อยา')['row']);
        $this->assertSame(4, $errors->firstWhere('reason', 'ไม่ได้ระบุขนาดมาตรฐาน')['row']);
    }

    /** @test */
    public function bulk_import_rejects_a_file_missing_required_columns()
    {
        $csv = "Name,Dose\nSomething,5mg\n";
        $file = UploadedFile::fake()->createWithContent('medicines.csv', $csv);

        $response = $this->postJson('/api/medicine-catalog/bulk-import', ['file' => $file]);

        $response->assertStatus(422)->assertJsonFragment([
            'detail' => 'ไฟล์ขาดคอลัมน์ที่จำเป็น: ชื่อยา, ขนาดมาตรฐาน',
        ]);
        $this->assertDatabaseCount('medicine_catalog', 0);
    }

    /** @test */
    public function bulk_import_rejects_a_non_spreadsheet_file()
    {
        $file = UploadedFile::fake()->image('not-a-spreadsheet.png');

        $response = $this->postJson('/api/medicine-catalog/bulk-import', ['file' => $file]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    // ---- destroy ----

    /** @test */
    public function destroy_requires_authentication()
    {
        $medicine = MedicineCatalog::create(['drug_name' => 'Paracetamol']);

        $response = $this->deleteJson("/api/medicine-catalog/{$medicine->id}");

        $response->assertStatus(401);
        $this->assertDatabaseHas('medicine_catalog', ['id' => $medicine->id]);
    }

    /** @test */
    public function destroy_deletes_a_drug_nobody_is_using()
    {
        Sanctum::actingAs($this->makeNurse());
        $medicine = MedicineCatalog::create(['drug_name' => 'Paracetamol']);

        $response = $this->deleteJson("/api/medicine-catalog/{$medicine->id}");

        $response->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('medicine_catalog', ['id' => $medicine->id]);
    }

    /** @test */
    public function destroy_without_force_asks_for_confirmation_when_patients_are_using_the_drug()
    {
        Sanctum::actingAs($this->makeNurse());
        $medicine = MedicineCatalog::create(['drug_name' => 'Paracetamol']);
        $this->makePatientOnDrug('Paracetamol', 'PATIENT-001');
        $this->makePatientOnDrug('Paracetamol', 'PATIENT-002');

        $response = $this->deleteJson("/api/medicine-catalog/{$medicine->id}");

        $response->assertStatus(409)->assertJson([
            'needs_confirmation' => true,
            'patients_using_count' => 2,
        ]);
        $this->assertDatabaseHas('medicine_catalog', ['id' => $medicine->id]);
    }

    /** @test */
    public function destroy_counts_each_patient_once_even_if_the_drug_is_in_more_than_one_of_their_meals()
    {
        Sanctum::actingAs($this->makeNurse());
        $medicine = MedicineCatalog::create(['drug_name' => 'Paracetamol']);
        $patient = $this->makePatientOnDrug('Paracetamol', 'PATIENT-001');
        $lunchCassette = PatientMealCassette::create([
            'patient_id' => $patient->id,
            'meal' => 'lunch_before',
            'qr_code' => "CASSETTE-{$patient->id}-lunch_before",
        ]);
        Medication::create([
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $lunchCassette->id,
            'drug_name' => 'Paracetamol',
            'dose' => '1 เม็ด',
        ]);

        $response = $this->deleteJson("/api/medicine-catalog/{$medicine->id}");

        $response->assertStatus(409)->assertJson(['patients_using_count' => 1]);
    }

    /** @test */
    public function destroy_with_force_deletes_even_when_patients_are_using_the_drug()
    {
        Sanctum::actingAs($this->makeNurse());
        $medicine = MedicineCatalog::create(['drug_name' => 'Paracetamol']);
        $this->makePatientOnDrug('Paracetamol');

        $response = $this->deleteJson("/api/medicine-catalog/{$medicine->id}?force=1");

        $response->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('medicine_catalog', ['id' => $medicine->id]);
    }

    /** @test */
    public function destroy_never_touches_the_patients_own_medication_record()
    {
        Sanctum::actingAs($this->makeNurse());
        $medicine = MedicineCatalog::create(['drug_name' => 'Paracetamol', 'standard_dose' => '500mg']);
        $patient = $this->makePatientOnDrug('Paracetamol');
        $medicationId = Medication::where('patient_id', $patient->id)->first()->id;

        $this->deleteJson("/api/medicine-catalog/{$medicine->id}?force=1");

        $this->assertDatabaseHas('medications', ['id' => $medicationId, 'drug_name' => 'Paracetamol']);
    }

    /** @test */
    public function destroy_ignores_usage_by_a_patient_who_has_since_been_deleted()
    {
        Sanctum::actingAs($this->makeNurse());
        $medicine = MedicineCatalog::create(['drug_name' => 'Paracetamol']);
        $patient = $this->makePatientOnDrug('Paracetamol');
        $patient->delete();

        $response = $this->deleteJson("/api/medicine-catalog/{$medicine->id}");

        $response->assertStatus(200)->assertJson(['ok' => true]);
    }
}
