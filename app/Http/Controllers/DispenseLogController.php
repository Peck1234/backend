<?php

namespace App\Http\Controllers;

use App\Models\DispenseLog;

class DispenseLogController extends Controller
{
    public function index()
    {
        $logs = DispenseLog::with(['nurse', 'patient', 'medication'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(function (DispenseLog $log) {
                return [
                    'id' => $log->id,
                    'result' => $log->result,
                    'message' => $log->message,
                    'cassette_qr' => $log->cassette_qr,
                    'drug_name' => $log->medication->drug_name ?? null,
                    'nurse_name' => $log->nurse->full_name ?? null,
                    'patient_name' => $log->patient->full_name ?? null,
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

        return response()->json($logs);
    }
}
