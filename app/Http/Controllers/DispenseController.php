<?php

namespace App\Http\Controllers;

use App\Models\DispenseLog;
use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use App\Services\DispenseVerificationService;
use Illuminate\Http\Request;

class DispenseController extends Controller
{
    public function verify(Request $request)
    {
        $data = $request->validate([
            'nurse_qr' => 'required|string',
            'patient_qr' => 'required|string',
            'cassette_qr' => 'required|string',
        ]);

        // Same three lookups as before, just no longer short-circuited on the
        // first miss - the DispenseVerificationService needs all three (or
        // whichever come back null) to decide the verdict. On a QR scan this
        // small hospital-cart dataset makes the extra indexed lookups free;
        // the API response and every side effect below is unchanged.
        $nurse = Nurse::where('qr_code_nurse', $data['nurse_qr'])->first();
        $patient = Patient::where('qr_code_patient', $data['patient_qr'])->first();
        $medication = Medication::where('qr_code_cassette', $data['cassette_qr'])->first();

        $outcome = (new DispenseVerificationService())->evaluate($nurse, $patient, $medication);

        if ($outcome['should_dispense']) {
            $medication->update(['dispensed_at' => now()]);
        }

        return $this->respond($outcome, $nurse, $patient, $medication, $data['cassette_qr']);
    }

    private function respond(
        array $payload,
        ?Nurse $nurse,
        ?Patient $patient,
        ?Medication $medication,
        string $cassetteQr
    ) {
        DispenseLog::create([
            'medication_id' => $medication ? $medication->id : null,
            'patient_id' => $patient ? $patient->id : null,
            'nurse_id' => $nurse ? $nurse->id : null,
            'cassette_qr' => $cassetteQr,
            'result' => $payload['result'],
            'message' => $payload['message'],
        ]);

        // Only these three keys were ever part of the response contract -
        // 'should_dispense' is an internal signal from the verification
        // service for this method, not something the app should see.
        return response()->json([
            'result' => $payload['result'],
            'drug_name' => $payload['drug_name'],
            'message' => $payload['message'],
        ]);
    }
}
