<?php

namespace App\Http\Controllers;

use App\Models\MealTime;
use App\Models\Medication;
use App\Models\PatientMealCassette;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// The 8 fixed meal-round times. Everyone can read them; only admins change
// them, and a change applies to every patient's drugs in that round at once.
class MealTimeController extends Controller
{
    public function index()
    {
        return response()->json($this->rounds());
    }

    public function update(Request $request)
    {
        $meals = array_keys(PatientMealCassette::MEAL_TIMES);
        $rules = ['times' => 'required|array'];
        foreach ($meals as $meal) {
            $rules["times.$meal"] = ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        }
        $data = $request->validate($rules, [
            'times.required' => 'กรุณาระบุเวลา',
            'times.*.regex' => 'เวลาต้องอยู่ในรูปแบบ ชั่วโมง:นาที เช่น 07:30',
        ]);

        $changed = array_intersect_key($data['times'], array_flip($meals));
        $current = PatientMealCassette::mealTimes();

        DB::transaction(function () use ($changed, $current, $request) {
            foreach ($changed as $meal => $time) {
                if ($current[$meal] === $time) {
                    continue;
                }
                if ($time === PatientMealCassette::MEAL_TIMES[$meal]) {
                    MealTime::where('meal', $meal)->delete();
                } else {
                    MealTime::updateOrCreate(['meal' => $meal], ['time' => $time, 'updated_by' => $request->user()->id]);
                }
                // Reminders and the due list read medications.time_slot, so
                // every drug already in this round moves to the new time too.
                Medication::whereIn(
                    'patient_meal_cassette_id',
                    PatientMealCassette::where('meal', $meal)->select('id')
                )->update(['time_slot' => $time]);
            }
        });

        return response()->json($this->rounds());
    }

    private function rounds(): array
    {
        $times = PatientMealCassette::mealTimes();

        return collect(PatientMealCassette::MEAL_TIMES)
            ->map(fn ($default, $meal) => ['meal' => $meal, 'time' => $times[$meal], 'default_time' => $default])
            ->values()
            ->all();
    }
}
