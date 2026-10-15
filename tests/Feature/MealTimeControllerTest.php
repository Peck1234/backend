<?php

namespace Tests\Feature;

use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealTimeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function nurse(string $role): Nurse
    {
        return Nurse::create([
            'full_name' => "คุณ $role", 'username' => $role, 'role' => $role,
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-' . strtoupper($role),
        ]);
    }

    private function drugIn(string $meal, int $patientNo = 1): Medication
    {
        $patient = Patient::firstOrCreate(['qr_code_patient' => "PATIENT-00$patientNo"], ['full_name' => "ผู้ป่วย $patientNo"]);
        $this->postJson("/api/patients/{$patient->id}/meal-cassettes/assign-medicine", [
            'meal' => $meal, 'drug_name' => 'Losartan', 'dose' => '50 mg',
        ])->assertStatus(201);

        return Medication::latest('id')->first();
    }

    public function test_lists_the_eight_rounds_with_defaults()
    {
        $this->getJson('/api/meal-times')
            ->assertOk()
            ->assertJsonCount(8)
            ->assertJsonFragment(['meal' => 'breakfast_before', 'time' => '07:30', 'default_time' => '07:30']);
    }

    public function test_admin_change_moves_every_patients_drugs_in_that_round()
    {
        $first = $this->drugIn('breakfast_after', 1);
        $second = $this->drugIn('breakfast_after', 2);
        $other = $this->drugIn('lunch_after', 1);

        Sanctum::actingAs($this->nurse('admin'));
        $this->putJson('/api/meal-times', ['times' => ['breakfast_after' => '09:00']])
            ->assertOk()
            ->assertJsonFragment(['meal' => 'breakfast_after', 'time' => '09:00', 'default_time' => '08:30']);

        $this->assertSame('09:00', $first->fresh()->time_slot);
        $this->assertSame('09:00', $second->fresh()->time_slot);
        $this->assertSame('12:30', $other->fresh()->time_slot);

        // Drugs added afterwards get the new time too.
        $this->assertSame('09:00', $this->drugIn('breakfast_after', 3)->time_slot);
    }

    public function test_setting_a_round_back_to_its_default_clears_the_override()
    {
        $drug = $this->drugIn('dinner_before');
        Sanctum::actingAs($this->nurse('admin'));

        $this->putJson('/api/meal-times', ['times' => ['dinner_before' => '17:00']])->assertOk();
        $this->assertDatabaseHas('meal_times', ['meal' => 'dinner_before', 'time' => '17:00']);

        $this->putJson('/api/meal-times', ['times' => ['dinner_before' => '17:30']])->assertOk();
        $this->assertDatabaseMissing('meal_times', ['meal' => 'dinner_before']);
        $this->assertSame('17:30', $drug->fresh()->time_slot);
    }

    public function test_rejects_bad_times()
    {
        Sanctum::actingAs($this->nurse('admin'));

        $this->putJson('/api/meal-times', ['times' => ['lunch_before' => '25:00']])->assertStatus(422);
        $this->putJson('/api/meal-times', ['times' => ['lunch_before' => '7:30']])->assertStatus(422);
    }

    public function test_only_admins_can_change_times()
    {
        $this->putJson('/api/meal-times', ['times' => ['lunch_before' => '11:00']])->assertStatus(401);

        Sanctum::actingAs($this->nurse('nurse'));
        $this->putJson('/api/meal-times', ['times' => ['lunch_before' => '11:00']])->assertStatus(403);
        $this->assertDatabaseMissing('meal_times', ['meal' => 'lunch_before']);
    }
}
