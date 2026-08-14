<?php

namespace Tests\Unit;

use App\Models\CartSlot;
use App\Services\SlotAssignmentService;
use Tests\TestCase;

// Unit tests for the optimistic-locking / status rules behind assign & clear
// slot. No database involved: CartSlot instances are constructed in memory
// and never saved.
class SlotAssignmentServiceTest extends TestCase
{
    private SlotAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SlotAssignmentService();
    }

    private function makeSlot(string $status, int $version): CartSlot
    {
        $slot = new CartSlot(['slot_code' => 'SLOT-0001', 'status' => $status, 'version' => $version]);
        $slot->id = 1;
        return $slot;
    }

    private function makeOtherSlot(int $id, string $slotCode): CartSlot
    {
        $slot = new CartSlot(['slot_code' => $slotCode, 'status' => 'occupied']);
        $slot->id = $id;
        return $slot;
    }

    // ---- evaluateAssign ----

    /** @test */
    public function assign_rejects_a_stale_version_even_if_the_slot_would_otherwise_be_assignable()
    {
        $slot = $this->makeSlot('empty', 3);

        $result = $this->service->evaluateAssign($slot, 2, null);

        $this->assertSame('version_conflict', $result['status']);
    }

    /** @test */
    public function assign_rejects_when_the_patient_is_already_assigned_to_a_different_slot()
    {
        $slot = $this->makeSlot('empty', 1);
        $existing = $this->makeOtherSlot(9, 'SLOT-0009');

        $result = $this->service->evaluateAssign($slot, 1, $existing);

        $this->assertSame('patient_already_assigned', $result['status']);
        $this->assertSame('SLOT-0009', $result['existing_slot_code']);
    }

    /** @test */
    public function assign_rejects_when_the_slot_itself_is_already_occupied()
    {
        $slot = $this->makeSlot('occupied', 1);

        $result = $this->service->evaluateAssign($slot, 1, null);

        $this->assertSame('slot_occupied', $result['status']);
    }

    /** @test */
    public function assign_succeeds_when_version_matches_slot_is_empty_and_patient_is_free()
    {
        $slot = $this->makeSlot('empty', 1);

        $result = $this->service->evaluateAssign($slot, 1, null);

        $this->assertSame('ok', $result['status']);
    }

    /** @test */
    public function assign_checks_version_before_the_other_two_rules()
    {
        // Stale version AND slot occupied AND patient already elsewhere - the
        // version conflict must win, matching the original controller's order.
        $slot = $this->makeSlot('occupied', 5);
        $existing = $this->makeOtherSlot(9, 'SLOT-0009');

        $result = $this->service->evaluateAssign($slot, 1, $existing);

        $this->assertSame('version_conflict', $result['status']);
    }

    // ---- evaluateClear ----

    /** @test */
    public function clear_treats_an_already_empty_slot_as_a_harmless_no_op_regardless_of_version()
    {
        $slot = $this->makeSlot('empty', 7);

        $result = $this->service->evaluateClear($slot, 1); // wrong version, doesn't matter

        $this->assertSame('already_empty', $result['status']);
    }

    /** @test */
    public function clear_rejects_a_stale_version_on_an_occupied_slot()
    {
        $slot = $this->makeSlot('occupied', 3);

        $result = $this->service->evaluateClear($slot, 2);

        $this->assertSame('version_conflict', $result['status']);
    }

    /** @test */
    public function clear_succeeds_when_the_slot_is_occupied_and_the_version_matches()
    {
        $slot = $this->makeSlot('occupied', 3);

        $result = $this->service->evaluateClear($slot, 3);

        $this->assertSame('ok', $result['status']);
    }

    /** @test */
    public function clear_checks_empty_status_before_version()
    {
        // Empty slot with a stale version - "already empty" wins, matching
        // the original controller's order (status checked first in clear()).
        $slot = $this->makeSlot('empty', 5);

        $result = $this->service->evaluateClear($slot, 999);

        $this->assertSame('already_empty', $result['status']);
    }
}
