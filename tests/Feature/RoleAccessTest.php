<?php

namespace Tests\Feature;

use App\Models\CartSlot;
use App\Models\MedicineCatalog;
use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function nurse(string $username, string $role = 'nurse'): Nurse
    {
        return Nurse::create([
            'full_name' => "คุณ $username", 'username' => $username, 'role' => $role,
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-' . strtoupper($username),
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function deleteActions(): array
    {
        $patient = Patient::create(['full_name' => 'ผู้ป่วย', 'qr_code_patient' => 'PATIENT-001']);
        $cassette = PatientMealCassette::getOrCreateFor($patient->id, 'breakfast_after');
        $medication = Medication::create([
            'patient_id' => $patient->id, 'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Losartan', 'dose' => '50 mg', 'qr_code_cassette' => $cassette->qr_code,
        ]);
        $catalog = MedicineCatalog::create(['drug_name' => 'Paracetamol', 'standard_dose' => '500 mg']);
        $slot = CartSlot::create(['slot_code' => 'SLOT-01', 'slot_no' => 1, 'version' => 1]);

        return [
            'delete patient' => ['DELETE', "/api/patients/{$patient->id}"],
            'remove drug from meal' => ['DELETE', "/api/patients/{$patient->id}/meal-cassettes/{$cassette->id}/medications/{$medication->id}"],
            'delete catalog medicine' => ['DELETE', "/api/medicine-catalog/{$catalog->id}"],
            'clear slot' => ['PUT', "/api/slots/{$slot->id}/clear"],
        ];
    }

    /** @test */
    public function delete_actions_need_login()
    {
        foreach ($this->deleteActions() as $label => [$method, $url]) {
            $this->json($method, $url, ['base_version' => 1])->assertStatus(401, $label);
        }
    }

    /** @test */
    public function a_dispensing_nurse_cannot_delete_anything()
    {
        Sanctum::actingAs($this->nurse('somsri'));

        foreach ($this->deleteActions() as $label => [$method, $url]) {
            $this->json($method, $url, ['base_version' => 1])
                ->assertStatus(403)
                ->assertJson(['detail' => 'เฉพาะผู้ดูแลระบบเท่านั้นที่ทำรายการนี้ได้']);
        }
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, Medication::count());
        $this->assertSame(1, MedicineCatalog::count());
    }

    /** @test */
    public function an_admin_can_delete()
    {
        Sanctum::actingAs($this->nurse('peck', 'admin'));

        foreach ($this->deleteActions() as $label => [$method, $url]) {
            $status = $this->json($method, $url, ['base_version' => 1])->status();
            $this->assertNotContains($status, [401, 403], "$label returned $status");
        }
    }

    /** @test */
    public function login_and_register_report_the_role_and_new_accounts_are_nurses()
    {
        $this->nurse('peck', 'admin');
        $this->postJson('/api/login', ['username' => 'peck', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('role', 'admin');

        $this->postJson('/api/register', [
            'full_name' => 'สมศรี ใหม่', 'username' => 'somsri', 'email' => 'somsri@example.com', 'password' => 'secret123',
        ])->assertStatus(201)->assertJsonPath('role', 'nurse');
        $this->assertSame('nurse', Nurse::where('username', 'somsri')->value('role'));
    }

    /** @test */
    public function a_new_account_cannot_make_itself_an_admin()
    {
        $this->postJson('/api/register', [
            'full_name' => 'แอบ', 'username' => 'sneaky', 'email' => 'sneaky@example.com', 'password' => 'secret123', 'role' => 'admin',
        ])->assertStatus(201);
        $this->assertSame('nurse', Nurse::where('username', 'sneaky')->value('role'));

        $nurse = Nurse::where('username', 'sneaky')->first();
        Sanctum::actingAs($nurse);
        $this->putJson('/api/profile', [
            'full_name' => 'แอบ', 'username' => 'sneaky', 'email' => 'sneaky@example.com', 'role' => 'admin',
        ])->assertOk();
        $this->assertSame('nurse', $nurse->fresh()->role);
    }

    /** @test */
    public function only_admins_manage_roles()
    {
        $admin = $this->nurse('peck', 'admin');
        $nurse = $this->nurse('somsri');

        Sanctum::actingAs($nurse);
        $this->getJson('/api/nurses')->assertStatus(403);
        $this->putJson("/api/nurses/{$nurse->id}/role", ['role' => 'admin'])->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->getJson('/api/nurses')->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.username', 'peck')
            ->assertJsonPath('0.role', 'admin');

        $this->putJson("/api/nurses/{$nurse->id}/role", ['role' => 'admin'])->assertOk()->assertJsonPath('role', 'admin');
        $this->assertTrue($nurse->fresh()->isAdmin());

        $this->putJson("/api/nurses/{$nurse->id}/role", ['role' => 'nurse'])->assertOk();
        $this->putJson("/api/nurses/{$nurse->id}/role", ['role' => 'boss'])->assertStatus(422);
    }

    /** @test */
    public function an_admin_cannot_remove_their_own_admin_role()
    {
        $admin = $this->nurse('peck', 'admin');
        Sanctum::actingAs($admin);

        $this->putJson("/api/nurses/{$admin->id}/role", ['role' => 'nurse'])
            ->assertStatus(422)
            ->assertJson(['detail' => 'ไม่สามารถถอดสิทธิ์ผู้ดูแลของตัวเองได้']);
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    /** @test */
    public function an_admin_can_delete_another_account_and_its_history_keeps_the_name()
    {
        $admin = $this->nurse('peck', 'admin');
        $somsri = $this->nurse('somsri');
        $somsri->update(['email' => 'somsri@example.com']);
        $token = $somsri->createToken('phone')->plainTextToken;

        $patient = Patient::create(['full_name' => 'ผู้ป่วย', 'qr_code_patient' => 'PATIENT-001']);
        $cassette = PatientMealCassette::getOrCreateFor($patient->id, 'breakfast_after');
        $med = Medication::create([
            'patient_id' => $patient->id, 'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => 'Losartan', 'dose' => '50 mg', 'qr_code_cassette' => $cassette->qr_code,
        ]);
        \App\Models\DispenseLog::create([
            'medication_id' => $med->id, 'patient_id' => $patient->id, 'nurse_id' => $somsri->id,
            'cassette_qr' => $cassette->qr_code, 'result' => 'correct', 'message' => '',
        ]);

        // A dispensing nurse can't delete accounts.
        Sanctum::actingAs($this->nurse('other'));
        $this->deleteJson("/api/nurses/{$somsri->id}")->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/nurses/{$somsri->id}")->assertOk();

        // Gone from the list, can't log in, its saved login no longer works.
        $this->getJson('/api/nurses')->assertJsonMissing(['username' => 'somsri']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['username' => 'somsri', 'password' => 'secret123'])->assertStatus(401);
        $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/user')->assertStatus(401);

        // The name and email can be used for a new account again.
        $this->postJson('/api/register', [
            'full_name' => 'สมศรี ใหม่', 'username' => 'somsri', 'email' => 'somsri@example.com', 'password' => 'secret123',
        ])->assertStatus(201);

        // History still shows who dispensed.
        Sanctum::actingAs($admin);
        $this->getJson("/api/patients/{$patient->id}/dispense-history")
            ->assertOk()->assertJsonPath('dispenses.0.nurse_name', 'คุณ somsri');
    }

    /** @test */
    public function an_admin_cannot_delete_their_own_account()
    {
        $admin = $this->nurse('peck', 'admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/nurses/{$admin->id}")
            ->assertStatus(422)->assertJson(['detail' => 'ไม่สามารถลบบัญชีของตัวเองได้']);
        $this->assertNotNull(Nurse::find($admin->id));
    }

    /** @test */
    public function the_make_admin_command_promotes_an_existing_account()
    {
        $this->nurse('peck');

        $this->artisan('nurse:make-admin', ['username' => 'peck'])->assertExitCode(0);
        $this->assertSame('admin', Nurse::where('username', 'peck')->value('role'));

        $this->artisan('nurse:make-admin', ['username' => 'nobody'])->assertExitCode(1);
    }
}
