<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Services\MedicationRecordWorkbook;
use App\Services\PatientDispenseHistory;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PatientDispenseHistoryController extends Controller
{
    // GET /patients/{patient}/dispense-history?from=YYYY-MM-DD&to=YYYY-MM-DD
    public function index(Request $request, Patient $patient)
    {
        $history = $this->historyFor($request, $patient);

        return response()->json([
            'patient' => [
                'patient_id' => $patient->id,
                'full_name' => $patient->full_name,
                'ward' => $patient->ward,
                'bed_no' => $patient->bed_no,
            ],
            'from' => $history->from->format('Y-m-d'),
            'to' => $history->to->format('Y-m-d'),
            'dispenses' => $history->dispenses()->map(function ($d) {
                $meal = PatientDispenseHistory::mealOf($d['medication']);

                return [
                    'id' => $d['id'],
                    'dispensed_at' => $d['at']->toIso8601String(),
                    'date' => $d['at']->format('Y-m-d'),
                    'time' => $d['at']->format('H:i'),
                    'drug_name' => $d['medication']->drug_name,
                    'dose' => $d['medication']->dose,
                    'meal' => $meal,
                    'meal_label' => PatientDispenseHistory::MEAL_LABELS[$meal] ?? null,
                    'nurse_name' => $d['nurse_name'],
                ];
            })->values(),
        ]);
    }

    // GET /patients/{patient}/dispense-history/export?from=...&to=... -> .xlsx
    public function export(Request $request, Patient $patient)
    {
        $workbook = new MedicationRecordWorkbook($this->historyFor($request, $patient));

        return $this->download($workbook->buildHistory(), $workbook->historyFileName());
    }

    private function download($book, string $fileName)
    {
        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function historyFor(Request $request, Patient $patient): PatientDispenseHistory
    {
        $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ], [
            'from.date_format' => 'รูปแบบวันที่เริ่มต้นไม่ถูกต้อง',
            'to.date_format' => 'รูปแบบวันที่สิ้นสุดไม่ถูกต้อง',
        ]);

        $history = PatientDispenseHistory::fromRequestDates($patient, $request->query('from'), $request->query('to'));

        if ($history->from->gt($history->to)) {
            abort(response()->json(['detail' => 'วันที่เริ่มต้นต้องไม่เกินวันที่สิ้นสุด'], 422));
        }
        if ($history->from->diffInDays($history->to) + 1 > PatientDispenseHistory::MAX_DAYS) {
            abort(response()->json(['detail' => 'เลือกช่วงวันที่ได้ไม่เกิน ' . PatientDispenseHistory::MAX_DAYS . ' วัน'], 422));
        }

        return $history;
    }
}
