<?php

namespace App\Http\Controllers;

use App\Models\CartSlot;
use App\Models\Nurse;
use App\Models\Patient;
use App\Models\SlotAuditLog;
use App\Services\SlotAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlotController extends Controller
{
    public function index()
    {
        $slots = CartSlot::with('currentPatient')
            ->orderBy('slot_no')
            ->get()
            ->map(function (CartSlot $slot) {
                return $this->serialize($slot);
            });

        return response()->json($slots);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'slot_code' => 'required|string|max:255|unique:cart_slots,slot_code',
            'slot_no' => 'nullable|integer|min:1',
            'created_by' => 'nullable|integer|exists:nurses,id',
        ]);

        try {
            $slot = DB::transaction(function () use ($validated) {
                $slot = CartSlot::create([
                    'slot_code' => $validated['slot_code'],
                    'slot_no' => $validated['slot_no'] ?? null,
                    'status' => 'empty',
                    'created_by' => $validated['created_by'] ?? null,
                    'version' => 1,
                ]);

                $this->writeLog($slot, $validated['created_by'] ?? null, 'create', null, [
                    'slot_code' => $slot->slot_code,
                    'slot_no' => $slot->slot_no,
                ]);

                return $slot;
            });
        } catch (QueryException $e) {
            return response()->json(['detail' => 'รหัสช่องนี้ถูกใช้ไปแล้ว'], 422);
        }

        return response()->json($this->serialize($slot), 201);
    }

    public function assign(Request $request, CartSlot $slot)
    {
        $validated = $request->validate([
            'patient_id' => 'required|integer|exists:patients,id',
            'staff_id' => 'nullable|integer|exists:nurses,id',
            'base_version' => 'required|integer',
        ]);

        $alreadyElsewhere = CartSlot::where('current_patient_id', $validated['patient_id'])
            ->where('id', '!=', $slot->id)
            ->first();

        $outcome = (new SlotAssignmentService())->evaluateAssign($slot, $validated['base_version'], $alreadyElsewhere);

        if ($outcome['status'] === 'version_conflict') {
            $this->writeLog($slot, $validated['staff_id'] ?? null, 'reject', $this->slotSnapshot($slot), [
                'attempted_action' => 'assign',
                'attempted_patient_id' => $validated['patient_id'],
            ]);

            return response()->json([
                'detail' => 'ช่องนี้ถูกแก้ไขไปแล้วโดยเครื่องอื่น กรุณาโหลดข้อมูลใหม่แล้วลองอีกครั้ง',
                'server_state' => $this->serialize($slot->fresh('currentPatient')),
            ], 409);
        }

        if ($outcome['status'] === 'patient_already_assigned') {
            return response()->json([
                'detail' => "ผู้ป่วยรายนี้ผูกกับช่อง {$outcome['existing_slot_code']} อยู่แล้ว",
            ], 422);
        }

        if ($outcome['status'] === 'slot_occupied') {
            return response()->json(['detail' => 'ช่องนี้มีผู้ป่วยอยู่แล้ว'], 422);
        }

        $before = $this->slotSnapshot($slot);
        $patient = Patient::findOrFail($validated['patient_id']);

        DB::transaction(function () use ($slot, $patient, $validated, $before) {
            $slot->update([
                'status' => 'occupied',
                'current_patient_id' => $patient->id,
                'occupied_at' => now(),
                'version' => $slot->version + 1,
            ]);

            $this->writeLog($slot, $validated['staff_id'] ?? null, 'assign', $before, $this->slotSnapshot($slot->fresh()));
        });

        return response()->json(['status' => 'assigned', 'version' => $slot->fresh()->version]);
    }

    public function clear(Request $request, CartSlot $slot)
    {
        $validated = $request->validate([
            'staff_id' => 'nullable|integer|exists:nurses,id',
            'base_version' => 'required|integer',
        ]);

        $outcome = (new SlotAssignmentService())->evaluateClear($slot, $validated['base_version']);

        if ($outcome['status'] === 'already_empty') {
            return response()->json(['status' => 'already-empty', 'version' => $slot->version]);
        }

        if ($outcome['status'] === 'version_conflict') {
            $this->writeLog($slot, $validated['staff_id'] ?? null, 'reject', $this->slotSnapshot($slot), [
                'attempted_action' => 'clear',
            ]);

            return response()->json([
                'detail' => 'ช่องนี้ถูกแก้ไขไปแล้วโดยเครื่องอื่น กรุณาโหลดข้อมูลใหม่แล้วลองอีกครั้ง',
                'server_state' => $this->serialize($slot->fresh('currentPatient')),
            ], 409);
        }

        $before = $this->slotSnapshot($slot);

        DB::transaction(function () use ($slot, $validated, $before) {
            $slot->update([
                'status' => 'empty',
                'current_patient_id' => null,
                'occupied_at' => null,
                'version' => $slot->version + 1,
            ]);

            $this->writeLog($slot, $validated['staff_id'] ?? null, 'discharge', $before, $this->slotSnapshot($slot->fresh()));
        });

        return response()->json(['status' => 'cleared', 'version' => $slot->fresh()->version]);
    }

    public function history(CartSlot $slot)
    {
        $logs = $slot->auditLogs()
            ->with('staff')
            ->orderByDesc('occurred_at')
            ->get()
            ->map(function (SlotAuditLog $log) {
                return [
                    'action' => $log->action,
                    'before' => $log->before,
                    'after' => $log->after,
                    'staff_name' => optional($log->staff)->full_name,
                    'occurred_at' => optional($log->occurred_at)->toIso8601String(),
                ];
            });

        return response()->json($logs);
    }

    private function serialize(CartSlot $slot)
    {
        return [
            'id' => $slot->id,
            'slot_code' => $slot->slot_code,
            'slot_no' => $slot->slot_no,
            'status' => $slot->status,
            'version' => $slot->version,
            'occupied_at' => optional($slot->occupied_at)->toIso8601String(),
            'current_patient' => $slot->currentPatient ? [
                'patient_id' => $slot->currentPatient->id,
                'full_name' => $slot->currentPatient->full_name,
                'ward' => $slot->currentPatient->ward,
                'bed_no' => $slot->currentPatient->bed_no,
            ] : null,
        ];
    }

    private function slotSnapshot(CartSlot $slot)
    {
        return [
            'status' => $slot->status,
            'current_patient_id' => $slot->current_patient_id,
            'version' => $slot->version,
        ];
    }

    private function writeLog(CartSlot $slot, ?int $staffId, string $action, ?array $before, ?array $after)
    {
        SlotAuditLog::create([
            'client_log_id' => (string) Str::uuid(),
            'slot_id' => $slot->id,
            'staff_id' => $staffId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'occurred_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
