<?php

namespace App\Services;

use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;

// Pure decision logic for "given these entities, can we show/dispense this
// cassette's medications?" - no DB writes, no QR-to-model lookups (the
// controller does those). Cassette-level now, not per-medication: a QR scan
// used to resolve straight to one drug, now it resolves to a meal that may
// hold several, so the verdict is about the cassette as a whole. Per-drug
// dispensed-today checks happen separately, per medication, in the actual
// dispense step - a cassette can be legitimately re-scanned after some of
// its drugs are already given today (e.g. a nurse comes back to give the
// rest), so that's not a reason to reject the cassette match itself.
class DispenseVerificationService
{
    public function evaluateCassette(?Nurse $nurse, ?Patient $patient, ?PatientMealCassette $cassette): array
    {
        if (!$nurse) {
            return $this->incorrect('ไม่พบข้อมูลพยาบาลจาก QR นี้');
        }

        if (!$patient) {
            return $this->incorrect('ไม่พบข้อมูลผู้ป่วยจาก QR นี้');
        }

        if (!$cassette) {
            return $this->incorrect('QR code ไม่ถูกต้อง');
        }

        if ($cassette->patient_id !== $patient->id) {
            return $this->incorrect('ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้');
        }

        return [
            'result' => 'correct',
            'message' => 'ตลับยาถูกต้อง',
        ];
    }

    private function incorrect(string $message): array
    {
        return [
            'result' => 'incorrect',
            'message' => $message,
        ];
    }
}
