<?php

namespace App\Services;

use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;

// Pure decision logic extracted from DispenseController::verify() so the
// "given these entities, is this dispense correct?" rule can be unit tested
// without a database - the controller still does the actual QR-to-model
// lookups and the dispensed_at write, this class only decides the verdict.
// Same checks, same order, same messages as before the extraction.
class DispenseVerificationService
{
    public function evaluate(?Nurse $nurse, ?Patient $patient, ?Medication $medication): array
    {
        if (!$nurse) {
            return $this->incorrect('ไม่พบข้อมูลพยาบาลจาก QR นี้', null);
        }

        if (!$patient) {
            return $this->incorrect('ไม่พบข้อมูลผู้ป่วยจาก QR นี้', null);
        }

        if (!$medication) {
            return $this->incorrect('QR code ไม่ถูกต้อง', null);
        }

        if ($medication->patient_id !== $patient->id) {
            return $this->incorrect('ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้', $medication->drug_name);
        }

        if ($medication->isDispensedToday()) {
            return $this->incorrect('ยานี้ถูกจ่ายไปแล้ววันนี้', $medication->drug_name);
        }

        return [
            'result' => 'correct',
            'drug_name' => $medication->drug_name,
            'message' => 'จ่ายยาสำเร็จ',
            'should_dispense' => true,
        ];
    }

    private function incorrect(string $message, ?string $drugName): array
    {
        return [
            'result' => 'incorrect',
            'drug_name' => $drugName,
            'message' => $message,
            'should_dispense' => false,
        ];
    }
}
