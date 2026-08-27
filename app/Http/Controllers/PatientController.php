<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{
    public function index()
    {
        $patients = Patient::with('cartSlot')->get()->map(function (Patient $patient) {
            return [
                'patient_id' => $patient->id,
                'full_name' => $patient->full_name,
                'ward' => $patient->ward,
                'bed_no' => $patient->bed_no,
                // Derived from cart_slots (the slot-management system) rather than the
                // legacy patients.slot_no/qr_code_slot columns, so patients assigned via
                // the new "จัดการช่องยา" tab still show up correctly in the AR locator.
                'slot_no' => optional($patient->cartSlot)->slot_no,
                'qr_code_patient' => $patient->qr_code_patient,
                'qr_code_slot' => optional($patient->cartSlot)->slot_code,
            ];
        });

        return response()->json($patients);
    }

    public function medications(Patient $patient)
    {
        $medications = $patient->medications
            ->sortBy(function ($medication) {
                if (!$medication->time_slot) {
                    return '99:99';
                }

                return collect(explode(',', $medication->time_slot))->min();
            })
            ->values()
            ->map(function ($medication) {
                return [
                    'order_id' => $medication->id,
                    'drug_name' => $medication->drug_name,
                    'standard_dose' => $medication->standard_dose,
                    'purpose' => $medication->purpose,
                    'dose' => $medication->dose,
                    'instruction' => $medication->instruction,
                    'time_slot' => $medication->time_slot,
                    'meal' => optional($medication->mealCassette)->meal,
                    'qr_code_cassette' => optional($medication->mealCassette)->qr_code,
                    'dispensed_at' => optional($medication->dispensed_at)->toIso8601String(),
                    'is_dispensed_today' => $medication->isDispensedToday(),
                    'is_due' => $medication->isDueNow(),
                ];
            });

        return response()->json([
            'medications' => $medications,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        try {
            $patient = DB::transaction(function () use ($validated) {
                $patient = Patient::create([
                    'full_name' => $validated['full_name'],
                    'ward' => $validated['ward'] ?? null,
                    'bed_no' => $validated['bed_no'] ?? null,
                    'qr_code_patient' => $validated['qr_code_patient'],
                ]);

                foreach ($validated['medications'] ?? [] as $med) {
                    $cassette = PatientMealCassette::getOrCreateFor($patient->id, $med['meal']);
                    $patient->medications()->create($this->medicationFields($patient, $cassette, $med));
                }

                return $patient;
            });
        } catch (QueryException $e) {
            return response()->json(['detail' => 'รหัส QR ซ้ำกับที่มีอยู่แล้วในระบบ'], 422);
        }

        return response()->json(['patient_id' => $patient->id], 201);
    }

    public function update(Request $request, Patient $patient)
    {
        $validated = $this->validatePayload($request, $patient);

        try {
            DB::transaction(function () use ($validated, $patient) {
                $patient->update([
                    'full_name' => $validated['full_name'],
                    'ward' => $validated['ward'] ?? null,
                    'bed_no' => $validated['bed_no'] ?? null,
                    'qr_code_patient' => $validated['qr_code_patient'],
                ]);

                $keepIds = [];
                foreach ($validated['medications'] ?? [] as $med) {
                    // Re-resolve the cassette every time (not just for new
                    // rows) - the nurse may have moved this drug to a
                    // different meal, and get-or-create is cheap/idempotent
                    // when the meal didn't actually change.
                    $cassette = PatientMealCassette::getOrCreateFor($patient->id, $med['meal']);
                    $fields = $this->medicationFields($patient, $cassette, $med);

                    if (!empty($med['id'])) {
                        $medication = Medication::where('patient_id', $patient->id)->findOrFail($med['id']);
                        $medication->update($fields);
                        $keepIds[] = $medication->id;
                    } else {
                        $created = $patient->medications()->create($fields);
                        $keepIds[] = $created->id;
                    }
                }

                $patient->medications()->whereNotIn('id', $keepIds)->delete();
            });
        } catch (QueryException $e) {
            return response()->json(['detail' => 'รหัส QR ซ้ำกับที่มีอยู่แล้วในระบบ'], 422);
        }

        return response()->json(['ok' => true]);
    }

    private function validatePayload(Request $request, ?Patient $patient = null)
    {
        $patientId = $patient ? $patient->id : null;

        return $request->validate([
            'full_name' => 'required|string|max:255',
            'ward' => 'nullable|string|max:255',
            'bed_no' => 'nullable|string|max:255',
            'qr_code_patient' => 'required|string|max:255|unique:patients,qr_code_patient,' . $patientId,
            'medications' => 'array',
            'medications.*.id' => 'nullable|integer',
            'medications.*.drug_name' => 'required_with:medications|string|max:255',
            'medications.*.standard_dose' => 'nullable|string|max:255',
            'medications.*.purpose' => 'nullable|string|max:255',
            'medications.*.dose' => 'required_with:medications|string|max:255',
            'medications.*.instruction' => 'nullable|string|max:255',
            'medications.*.meal' => 'required_with:medications|in:' . implode(',', PatientMealCassette::MEALS),
        ]);
    }

    private function medicationFields(Patient $patient, PatientMealCassette $cassette, array $med)
    {
        return [
            'patient_id' => $patient->id,
            'patient_meal_cassette_id' => $cassette->id,
            'drug_name' => $med['drug_name'],
            'standard_dose' => isset($med['standard_dose']) ? $med['standard_dose'] : null,
            'purpose' => isset($med['purpose']) ? $med['purpose'] : null,
            'dose' => $med['dose'],
            'instruction' => isset($med['instruction']) ? $med['instruction'] : null,
            'time_slot' => PatientMealCassette::MEAL_TIMES[$med['meal']] ?? null,
        ];
    }
}
