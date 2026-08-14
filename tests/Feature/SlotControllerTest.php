<?php

namespace Tests\Feature;

use App\Models\CartSlot;
use App\Models\Nurse;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// HTTP-level coverage for the cart-slot endpoints. SlotAssignmentServiceTest
// (Unit) already covers every branch of the optimistic-locking decision logic
// in isolation; this exercises the full stack around it - persistence, the
// append-only slot_audit_logs writes, and the exact response bodies/status
// codes the app depends on for each outcome.
class SlotControllerTest extends TestCase
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

    private function makeNurse(): Nurse
    {
        return Nurse::create([
            'full_name' => 'พยาบาล ทดสอบ',
            'username' => 'nurse1',
            'password' => Hash::make('secret123'),
            'qr_code_nurse' => 'NURSE-001',
        ]);
    }

    private function makeSlot(string $status = 'empty', int $version = 1, ?int $patientId = null): CartSlot
    {
        return CartSlot::create([
            'slot_code' => 'SLOT-0001',
            'slot_no' => 1,
            'status' => $status,
            'current_patient_id' => $patientId,
            'version' => $version,
        ]);
    }

    /** @test */
    public function index_lists_slots_with_their_current_patient()
    {
        $patient = $this->makePatient();
        $this->makeSlot('occupied', 1, $patient->id);

        $response = $this->getJson('/api/slots');

        $response->assertStatus(200)->assertJsonFragment([
            'slot_code' => 'SLOT-0001',
            'status' => 'occupied',
        ]);
        $response->assertJsonPath('0.current_patient.patient_id', $patient->id);
    }

    /** @test */
    public function store_creates_an_empty_slot_at_version_one()
    {
        $response = $this->postJson('/api/slots', ['slot_code' => 'SLOT-0002', 'slot_no' => 2]);

        $response->assertStatus(201)->assertJson([
            'slot_code' => 'SLOT-0002',
            'status' => 'empty',
            'version' => 1,
        ]);
        $this->assertDatabaseHas('cart_slots', ['slot_code' => 'SLOT-0002', 'version' => 1]);
    }

    /** @test */
    public function store_rejects_a_duplicate_slot_code()
    {
        // Caught by the 'unique:cart_slots,slot_code' validation rule before
        // the controller's transaction runs - see the equivalent note in
        // PatientControllerTest for why the QueryException catch isn't hit here.
        $this->makeSlot();

        $response = $this->postJson('/api/slots', ['slot_code' => 'SLOT-0001']);

        $response->assertStatus(422)->assertJsonValidationErrors('slot_code');
    }

    /** @test */
    public function assign_attaches_a_patient_and_increments_the_version()
    {
        $slot = $this->makeSlot('empty', 1);
        $patient = $this->makePatient();

        $response = $this->putJson("/api/slots/{$slot->id}/assign", [
            'patient_id' => $patient->id,
            'base_version' => 1,
        ]);

        $response->assertStatus(200)->assertJson(['status' => 'assigned', 'version' => 2]);
        $this->assertDatabaseHas('cart_slots', [
            'id' => $slot->id,
            'status' => 'occupied',
            'current_patient_id' => $patient->id,
            'version' => 2,
        ]);
        $this->assertDatabaseHas('slot_audit_logs', ['slot_id' => $slot->id, 'action' => 'assign']);
    }

    /** @test */
    public function assign_rejects_a_stale_base_version_with_409_and_the_current_server_state()
    {
        $slot = $this->makeSlot('empty', 3);
        $patient = $this->makePatient();

        $response = $this->putJson("/api/slots/{$slot->id}/assign", [
            'patient_id' => $patient->id,
            'base_version' => 1,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('server_state.version', 3);
        $this->assertDatabaseHas('slot_audit_logs', ['slot_id' => $slot->id, 'action' => 'reject']);
    }

    /** @test */
    public function assign_rejects_a_patient_already_assigned_to_a_different_slot()
    {
        $patient = $this->makePatient();
        $otherSlot = CartSlot::create(['slot_code' => 'SLOT-0009', 'status' => 'occupied', 'current_patient_id' => $patient->id, 'version' => 1]);
        $slot = $this->makeSlot('empty', 1);

        $response = $this->putJson("/api/slots/{$slot->id}/assign", [
            'patient_id' => $patient->id,
            'base_version' => 1,
        ]);

        $response->assertStatus(422)->assertJson(['detail' => 'ผู้ป่วยรายนี้ผูกกับช่อง SLOT-0009 อยู่แล้ว']);
    }

    /** @test */
    public function assign_rejects_an_already_occupied_slot()
    {
        $existingPatient = $this->makePatient('PATIENT-EXISTING');
        $slot = $this->makeSlot('occupied', 1, $existingPatient->id);
        $newPatient = $this->makePatient('PATIENT-NEW');

        $response = $this->putJson("/api/slots/{$slot->id}/assign", [
            'patient_id' => $newPatient->id,
            'base_version' => 1,
        ]);

        $response->assertStatus(422)->assertJson(['detail' => 'ช่องนี้มีผู้ป่วยอยู่แล้ว']);
    }

    /** @test */
    public function clear_empties_an_occupied_slot_and_increments_the_version()
    {
        $patient = $this->makePatient();
        $slot = $this->makeSlot('occupied', 1, $patient->id);

        $response = $this->putJson("/api/slots/{$slot->id}/clear", ['base_version' => 1]);

        $response->assertStatus(200)->assertJson(['status' => 'cleared', 'version' => 2]);
        $this->assertDatabaseHas('cart_slots', [
            'id' => $slot->id,
            'status' => 'empty',
            'current_patient_id' => null,
            'version' => 2,
        ]);
    }

    /** @test */
    public function clear_on_an_already_empty_slot_is_a_no_op_regardless_of_version()
    {
        $slot = $this->makeSlot('empty', 5);

        $response = $this->putJson("/api/slots/{$slot->id}/clear", ['base_version' => 999]);

        $response->assertStatus(200)->assertJson(['status' => 'already-empty', 'version' => 5]);
        $this->assertDatabaseMissing('slot_audit_logs', ['slot_id' => $slot->id]);
    }

    /** @test */
    public function clear_rejects_a_stale_base_version_on_an_occupied_slot()
    {
        $patient = $this->makePatient();
        $slot = $this->makeSlot('occupied', 3, $patient->id);

        $response = $this->putJson("/api/slots/{$slot->id}/clear", ['base_version' => 1]);

        $response->assertStatus(409);
        $this->assertDatabaseHas('cart_slots', ['id' => $slot->id, 'version' => 3]);
    }

    /** @test */
    public function history_returns_audit_log_entries_newest_first()
    {
        $nurse = $this->makeNurse();
        $slot = $this->makeSlot('empty', 1);
        $patient = $this->makePatient();

        $this->putJson("/api/slots/{$slot->id}/assign", [
            'patient_id' => $patient->id,
            'staff_id' => $nurse->id,
            'base_version' => 1,
        ]);
        $this->putJson("/api/slots/{$slot->id}/clear", [
            'staff_id' => $nurse->id,
            'base_version' => 2,
        ]);

        $response = $this->getJson("/api/slots/{$slot->id}/history");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertCount(2, $body);
        $this->assertSame('discharge', $body[0]['action']);
        $this->assertSame('assign', $body[1]['action']);
        $this->assertSame('พยาบาล ทดสอบ', $body[0]['staff_name']);
    }
}
