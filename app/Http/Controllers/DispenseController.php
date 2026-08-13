<?php

namespace App\Http\Controllers;

use App\Models\DispenseLog;
use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
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

        $nurse = Nurse::where('qr_code_nurse', $data['nurse_qr'])->first();
        if (!$nurse) {
            return $this->respond([
                'result' => 'incorrect',
                'drug_name' => null,
                'message' => 'ไม่พบข้อมูลพยาบาลจาก QR นี้',
            ], null, null, null, $data['cassette_qr']);
        }

        $patient = Patient::where('qr_code_patient', $data['patient_qr'])->first();
        if (!$patient) {
            return $this->respond([
                'result' => 'incorrect',
                'drug_name' => null,
                'message' => 'ไม่พบข้อมูลผู้ป่วยจาก QR นี้',
            ], $nurse, null, null, $data['cassette_qr']);
        }

        $medication = Medication::where('qr_code_cassette', $data['cassette_qr'])->first();
        if (!$medication) {
            return $this->respond([
                'result' => 'incorrect',
                'drug_name' => null,
                'message' => 'QR code ไม่ถูกต้อง',
            ], $nurse, $patient, null, $data['cassette_qr']);
        }

        if ($medication->patient_id !== $patient->id) {
            return $this->respond([
                'result' => 'incorrect',
                'drug_name' => $medication->drug_name,
                'message' => 'ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้',
            ], $nurse, $patient, $medication, $data['cassette_qr']);
        }

        if ($medication->isDispensedToday()) {
            return $this->respond([
                'result' => 'incorrect',
                'drug_name' => $medication->drug_name,
                'message' => 'ยานี้ถูกจ่ายไปแล้ววันนี้',
            ], $nurse, $patient, $medication, $data['cassette_qr']);
        }

        $medication->update(['dispensed_at' => now()]);

        return $this->respond([
            'result' => 'correct',
            'drug_name' => $medication->drug_name,
            'message' => 'จ่ายยาสำเร็จ',
        ], $nurse, $patient, $medication, $data['cassette_qr']);
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

        return response()->json($payload);
    }
}
