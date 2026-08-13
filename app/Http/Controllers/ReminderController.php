<?php

namespace App\Http\Controllers;

use App\Models\Medication;

class ReminderController extends Controller
{
    public function due()
    {
        $due = Medication::whereNotNull('time_slot')
            ->with('patient')
            ->get()
            ->map(function (Medication $medication) {
                if ($medication->isDispensedToday()) {
                    return null;
                }

                $matchedTime = $medication->matchedDueTime();

                if (!$matchedTime || !$medication->patient) {
                    return null;
                }

                return [
                    'order_id' => $medication->id,
                    'drug_name' => $medication->drug_name,
                    'time_slot' => $medication->time_slot,
                    'matched_time' => $matchedTime,
                    'patient_id' => $medication->patient->id,
                    'patient_name' => $medication->patient->full_name,
                    'ward' => $medication->patient->ward,
                    'bed_no' => $medication->patient->bed_no,
                    'qr_code_patient' => $medication->patient->qr_code_patient,
                    'qr_code_cassette' => $medication->qr_code_cassette,
                ];
            })
            ->filter()
            ->values();

        return response()->json($due);
    }

    // All distinct dose times still ahead today across every patient's pending
    // medications — used by the app to schedule native reminders for each round
    // (not just the ones that are already overdue, unlike due() above).
    //
    // time_slot is free-ish text in practice (some rows use "08.00", ranges like
    // "08:00-12:30-18:00(...)", or PRN notes like "เมื่อมีไข้ ทุก 4-6 ชั่วโมง") so
    // only segments that are cleanly "H:MM"/"HH:MM" are schedulable — anything
    // else (including PRN doses, which have no fixed time by definition) is
    // silently skipped rather than guessed at.
    public function scheduleTimes()
    {
        $times = Medication::whereNotNull('time_slot')
            ->with('patient')
            ->get()
            ->filter(function (Medication $medication) {
                return $medication->patient && !$medication->isDispensedToday();
            })
            ->flatMap(function (Medication $medication) {
                return explode(',', $medication->time_slot);
            })
            ->map(fn ($slot) => trim($slot))
            ->filter(fn ($slot) => preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $slot))
            ->map(function ($slot) {
                [$hour, $minute] = explode(':', $slot);
                return sprintf('%02d:%s', (int) $hour, $minute);
            })
            ->unique()
            ->sort()
            ->values();

        return response()->json(['times' => $times]);
    }
}
