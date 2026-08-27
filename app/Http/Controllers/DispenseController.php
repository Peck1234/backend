<?php

namespace App\Http\Controllers;

use App\Models\DispenseLog;
use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use App\Services\DispenseVerificationService;
use Illuminate\Http\Request;

class DispenseController extends Controller
{
    // Step 1: scan nurse + patient + cassette QR. Resolves to a whole meal,
    // not one drug - returns every medication currently in that cassette
    // (each with its own already-dispensed-today flag) for the checklist UI.
    // Read-only except for the audit log row; nothing gets marked dispensed
    // here.
    public function verifyCassette(Request $request)
    {
        $data = $request->validate([
            'nurse_qr' => 'required|string',
            'patient_qr' => 'required|string',
            'cassette_qr' => 'required|string',
        ]);

        $nurse = Nurse::where('qr_code_nurse', $data['nurse_qr'])->first();
        $patient = Patient::where('qr_code_patient', $data['patient_qr'])->first();
        $cassette = PatientMealCassette::where('qr_code', $data['cassette_qr'])->first();

        $outcome = (new DispenseVerificationService())->evaluateCassette($nurse, $patient, $cassette);

        DispenseLog::create([
            'medication_id' => null,
            'patient_id' => $patient ? $patient->id : null,
            'nurse_id' => $nurse ? $nurse->id : null,
            'cassette_qr' => $data['cassette_qr'],
            'result' => $outcome['result'],
            'message' => $outcome['message'],
        ]);

        if ($outcome['result'] !== 'correct') {
            return response()->json(['result' => $outcome['result'], 'message' => $outcome['message']]);
        }

        $medications = $cassette->medications->map(fn (Medication $m) => [
            'order_id' => $m->id,
            'drug_name' => $m->drug_name,
            'standard_dose' => $m->standard_dose,
            'purpose' => $m->purpose,
            'dose' => $m->dose,
            'instruction' => $m->instruction,
            'is_dispensed_today' => $m->isDispensedToday(),
        ]);

        return response()->json([
            'result' => 'correct',
            'message' => $outcome['message'],
            'cassette_id' => $cassette->id,
            'meal' => $cassette->meal,
            'medications' => $medications,
        ]);
    }

    // Step 2: the nurse has ticked one or more drugs from the checklist (or
    // used "confirm all") - dispense exactly those, nothing else. Re-verifies
    // nurse/patient/cassette identity itself rather than trusting whatever
    // the client remembers from verifyCassette, and skips (doesn't error on)
    // any requested medication that isn't actually in this cassette or was
    // already dispensed today, since the two calls aren't atomic with each
    // other - another nurse could have acted on the same cassette in between.
    public function dispenseMedications(Request $request)
    {
        $data = $request->validate([
            'nurse_qr' => 'required|string',
            'patient_qr' => 'required|string',
            'cassette_qr' => 'required|string',
            'medication_ids' => 'required|array|min:1',
            'medication_ids.*' => 'integer',
        ]);

        $nurse = Nurse::where('qr_code_nurse', $data['nurse_qr'])->first();
        $patient = Patient::where('qr_code_patient', $data['patient_qr'])->first();
        $cassette = PatientMealCassette::where('qr_code', $data['cassette_qr'])->first();

        $outcome = (new DispenseVerificationService())->evaluateCassette($nurse, $patient, $cassette);
        if ($outcome['result'] !== 'correct') {
            return response()->json(['detail' => $outcome['message']], 422);
        }

        $results = collect($data['medication_ids'])->map(function ($id) use ($cassette, $nurse, $patient) {
            $medication = $cassette->medications->firstWhere('id', $id);

            if (!$medication) {
                return ['order_id' => $id, 'result' => 'incorrect', 'message' => 'ยานี้ไม่ได้อยู่ในตลับนี้'];
            }
            if ($medication->isDispensedToday()) {
                return ['order_id' => $id, 'result' => 'incorrect', 'message' => 'ยานี้ถูกจ่ายไปแล้ววันนี้', 'drug_name' => $medication->drug_name];
            }

            $medication->update(['dispensed_at' => now()]);

            DispenseLog::create([
                'medication_id' => $medication->id,
                'patient_id' => $patient->id,
                'nurse_id' => $nurse->id,
                'cassette_qr' => $cassette->qr_code,
                'result' => 'correct',
                'message' => 'จ่ายยาสำเร็จ',
            ]);

            return ['order_id' => $id, 'result' => 'correct', 'message' => 'จ่ายยาสำเร็จ', 'drug_name' => $medication->drug_name];
        });

        return response()->json(['results' => $results]);
    }
}
