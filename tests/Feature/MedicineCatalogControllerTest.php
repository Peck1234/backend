<?php

namespace Tests\Feature;

use App\Models\MedicineCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MedicineCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

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
}
