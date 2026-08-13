<?php

namespace App\Http\Controllers;

use App\Models\DispenseLog;
use App\Models\Medication;
use Illuminate\Support\Facades\Date;

class StatsController extends Controller
{
    private const STANDARD_SLOTS = ['08:00', '12:00', '18:00', '21:00'];

    public function index()
    {
        $medications = Medication::with('patient')->get();

        $total = $medications->count();
        $dispensed = $medications->filter(fn (Medication $m) => $m->isDispensedToday())->count();
        $dispensedRate = $total > 0 ? round(($dispensed / $total) * 100, 1) : 0;

        return response()->json([
            'total' => $total,
            'dispensed' => $dispensed,
            'dispensed_rate' => $dispensedRate,
            'by_time_slot' => $this->byTimeSlot($medications),
            'by_ward' => $this->byWard($medications),
            'by_drug' => $this->byDrug($medications),
            'trend' => $this->trend(),
        ]);
    }

    private function dispensedTodayCount($collection)
    {
        return $collection->filter(fn (Medication $m) => $m->isDispensedToday())->count();
    }

    private function byTimeSlot($medications)
    {
        return collect(self::STANDARD_SLOTS)->map(function ($slot) use ($medications) {
            $inSlot = $medications->filter(function (Medication $medication) use ($slot) {
                return $medication->time_slot && in_array($slot, explode(',', $medication->time_slot));
            });

            return [
                'slot' => $slot,
                'total' => $inSlot->count(),
                'dispensed' => $this->dispensedTodayCount($inSlot),
            ];
        })->values();
    }

    private function byWard($medications)
    {
        return $medications
            ->filter(fn (Medication $medication) => $medication->patient)
            ->groupBy(fn (Medication $medication) => $medication->patient->ward ?? 'ไม่ระบุวอร์ด')
            ->map(function ($group, $ward) {
                return [
                    'ward' => $ward,
                    'total' => $group->count(),
                    'dispensed' => $this->dispensedTodayCount($group),
                ];
            })
            ->values();
    }

    private function byDrug($medications)
    {
        return $medications
            ->groupBy('drug_name')
            ->map(function ($group, $drugName) {
                return [
                    'drug_name' => $drugName,
                    'total' => $group->count(),
                    'dispensed' => $this->dispensedTodayCount($group),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    private function trend()
    {
        $days = collect(range(6, 0))->map(fn ($offset) => Date::now()->subDays($offset)->format('Y-m-d'));

        $dispensedByDay = DispenseLog::where('result', 'correct')
            ->get()
            ->groupBy(fn (DispenseLog $log) => $log->created_at->format('Y-m-d'));

        return $days->map(function ($date) use ($dispensedByDay) {
            return [
                'date' => $date,
                'dispensed' => $dispensedByDay->get($date, collect())->count(),
            ];
        })->values();
    }
}
