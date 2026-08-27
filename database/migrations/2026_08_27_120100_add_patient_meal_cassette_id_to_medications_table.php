<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Backfills every existing medication into the new meal-cassette model.
//
// A medication used to carry a free-text, often multi-time time_slot (e.g.
// "08:00,12:00,18:00" for a 3x/day drug, or PRN notes like "เมื่อมีไข้ ทุก
// 4-6 ชั่วโมง" - real values seen in this codebase's own comments). The new
// model wants exactly one row per (patient, meal): a drug given 3 times/day
// becomes 3 separate medication rows, one per meal, each pointing at that
// meal's cassette. A drug whose time_slot has no parseable clock time at all
// (PRN, freeform notes, or simply null) goes into a 5th 'prn' pseudo-meal
// instead of being silently dropped or guessed at.
class AddPatientMealCassetteIdToMedicationsTable extends Migration
{
    // Mirrors the segment regex ReminderController::scheduleTimes() already
    // uses for the same "is this actually a clock time" question - anything
    // that doesn't match (PRN text, empty, malformed) is deliberately treated
    // as unparseable rather than guessed at.
    private const TIME_SEGMENT_PATTERN = '/^([01]?\d|2[0-3]):[0-5]\d$/';

    private const MEAL_TIMES = [
        'breakfast' => '08:00',
        'lunch' => '12:00',
        'dinner' => '18:00',
        'bedtime' => '21:00',
    ];

    public function up()
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->dropUnique(['qr_code_cassette']);
            $table->string('qr_code_cassette')->nullable()->change();
            // cascadeOnDelete (not nullOnDelete) - the column becomes NOT NULL
            // by the end of this migration, and MySQL rejects a SET NULL
            // action on a non-nullable column outright ("Column ... cannot be
            // NOT NULL: needed in a foreign key constraint ... SET NULL").
            // Cascading also matches how patient_id already behaves here: a
            // medication can't outlive the thing it's scoped to.
            $table->foreignId('patient_meal_cassette_id')->nullable()->after('patient_id')
                ->constrained('patient_meal_cassettes')->cascadeOnDelete();
        });

        $cassetteCache = []; // "{patient_id}:{meal}" => cassette id, avoids a query per row

        $getOrCreateCassette = function (int $patientId, string $meal) use (&$cassetteCache) {
            $key = "{$patientId}:{$meal}";
            if (isset($cassetteCache[$key])) {
                return $cassetteCache[$key];
            }

            $existing = DB::table('patient_meal_cassettes')
                ->where('patient_id', $patientId)->where('meal', $meal)->first();
            if ($existing) {
                $cassetteCache[$key] = $existing->id;
                return $existing->id;
            }

            $id = DB::table('patient_meal_cassettes')->insertGetId([
                'patient_id' => $patientId,
                'meal' => $meal,
                'qr_code' => "CASSETTE-{$patientId}-{$meal}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $cassetteCache[$key] = $id;
            return $id;
        };

        DB::table('medications')->orderBy('id')->each(function ($medication) use ($getOrCreateCassette) {
            $meals = $this->mealsFor($medication->time_slot);

            if (empty($meals)) {
                $cassetteId = $getOrCreateCassette($medication->patient_id, 'prn');
                DB::table('medications')->where('id', $medication->id)->update([
                    'patient_meal_cassette_id' => $cassetteId,
                ]);
                return;
            }

            // First meal reuses the existing row; any additional meals for the
            // same drug (a 3x/day medication) get cloned into new rows - same
            // dose/instruction/purpose as the original, since the old schema
            // never recorded per-time-of-day differences to preserve. Flagged
            // in the API spec for a nurse to review after this migration runs.
            foreach ($meals as $index => $meal) {
                $cassetteId = $getOrCreateCassette($medication->patient_id, $meal);

                if ($index === 0) {
                    DB::table('medications')->where('id', $medication->id)->update([
                        'patient_meal_cassette_id' => $cassetteId,
                        'time_slot' => self::MEAL_TIMES[$meal],
                    ]);
                } else {
                    DB::table('medications')->insert([
                        'patient_id' => $medication->patient_id,
                        'patient_meal_cassette_id' => $cassetteId,
                        'drug_name' => $medication->drug_name,
                        'standard_dose' => $medication->standard_dose,
                        'purpose' => $medication->purpose,
                        'dose' => $medication->dose,
                        'instruction' => $medication->instruction,
                        'time_slot' => self::MEAL_TIMES[$meal],
                        'qr_code_cassette' => null,
                        'dispensed_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        Schema::table('medications', function (Blueprint $table) {
            $table->foreignId('patient_meal_cassette_id')->nullable(false)->change();
        });
    }

    // Distinct meals (in a stable order) this medication's time_slot maps to,
    // or [] if nothing in it parses as a real clock time.
    private function mealsFor(?string $timeSlot): array
    {
        if (!$timeSlot) {
            return [];
        }

        $meals = [];
        foreach (explode(',', $timeSlot) as $segment) {
            $segment = trim($segment);
            if (!preg_match(self::TIME_SEGMENT_PATTERN, $segment)) {
                continue;
            }
            $meal = $this->mealForTime($segment);
            if (!in_array($meal, $meals, true)) {
                $meals[] = $meal;
            }
        }
        return $meals;
    }

    private function mealForTime(string $hhmm): string
    {
        [$hour] = explode(':', $hhmm);
        $hour = (int) $hour;

        // >=20:00 or <05:00 first, since a naive ascending chain of `< N`
        // checks would otherwise catch an early-morning hour like 02:00 in
        // the very first `< 11` branch and misfile it as breakfast.
        if ($hour >= 20 || $hour < 5) return 'bedtime';
        if ($hour < 11) return 'breakfast';
        if ($hour < 16) return 'lunch';
        return 'dinner'; // 16:00-19:59
    }

    public function down()
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->dropForeign(['patient_meal_cassette_id']);
            $table->dropColumn('patient_meal_cassette_id');
            $table->string('qr_code_cassette')->nullable(false)->change();
            $table->unique('qr_code_cassette');
        });
    }
}
