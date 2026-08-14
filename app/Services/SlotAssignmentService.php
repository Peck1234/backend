<?php

namespace App\Services;

use App\Models\CartSlot;

// Pure decision logic extracted from SlotController::assign()/clear() so the
// optimistic-locking and status rules can be unit tested without a database.
// Same checks, same order, same outcomes as before the extraction - the
// controller still does the model lookups, the DB transaction, and the
// audit-log writes.
class SlotAssignmentService
{
    // $existingSlotForPatient: another slot (if any) this patient is already
    // assigned to, other than $slot itself - the controller fetches this
    // before calling in, same as it fetched it before this method existed.
    public function evaluateAssign(CartSlot $slot, int $requestedVersion, ?CartSlot $existingSlotForPatient): array
    {
        if ($requestedVersion !== $slot->version) {
            return ['status' => 'version_conflict'];
        }

        if ($existingSlotForPatient) {
            return ['status' => 'patient_already_assigned', 'existing_slot_code' => $existingSlotForPatient->slot_code];
        }

        if ($slot->status === 'occupied') {
            return ['status' => 'slot_occupied'];
        }

        return ['status' => 'ok'];
    }

    public function evaluateClear(CartSlot $slot, int $requestedVersion): array
    {
        // Status check comes before the version check here, matching the
        // original clear() - an already-empty slot is treated as a harmless
        // no-op regardless of what version the client thought it was on.
        if ($slot->status === 'empty') {
            return ['status' => 'already_empty'];
        }

        if ($requestedVersion !== $slot->version) {
            return ['status' => 'version_conflict'];
        }

        return ['status' => 'ok'];
    }
}
