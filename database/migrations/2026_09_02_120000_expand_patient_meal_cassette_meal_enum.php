<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Expands the meal-cassette enum from 5 values (breakfast/lunch/dinner/
// bedtime/prn) to 9 (each of the 4 real meals split into before/after food,
// plus prn) - see MEAL-CASSETTE-API-SPEC.md, "การเปลี่ยนแปลง 2026-09-02" for
// the full spec this implements. Frontend (c:\my-app) already shipped this
// same 9-value list in src/constants/meals.js - the two must match exactly.
//
// Existing patient_meal_cassettes rows use the OLD 5-value set, which is NOT
// a subset of the new 9 values - 'breakfast' has no single equivalent, it's
// replaced by 'breakfast_before'/'breakfast_after' with no way to infer from
// the old row alone which half of the meal it meant. Confirmed with the user
// this is dev-only test data (6 patients / 16 cassettes / 53 medications at
// the time this migration was written) with no real patients yet, and
// explicitly authorized to clear rather than attempt a lossy guess - so this
// deletes every cassette (medications cascade-delete with it via their
// patient_meal_cassette_id foreign key) before widening the enum.
//
// Driver-specific because MySQL's ENUM constraint can only be widened via a
// raw ALTER ... MODIFY (Schema::table()->enum()->change() goes through
// doctrine/dbal, which has no real concept of MySQL's ENUM type and won't
// reliably rewrite the constraint). SQLite (phpunit's test DB, see
// phpunit.xml) doesn't understand MySQL's MODIFY syntax at all - confirmed
// live, `php artisan test` failed every test that touches this table with
// "near MODIFY: syntax error" until this branch was added. SQLite has no
// data to preserve at this point (already cleared above), so the column is
// just dropped and re-added with the new enum instead of altered in place.
class ExpandPatientMealCassetteMealEnum extends Migration
{
    private const OLD_MEALS = ['breakfast', 'lunch', 'dinner', 'bedtime', 'prn'];

    private const NEW_MEALS = [
        'breakfast_before', 'breakfast_after',
        'lunch_before', 'lunch_after',
        'dinner_before', 'dinner_after',
        'bedtime_before', 'bedtime_after',
        'prn',
    ];

    public function up()
    {
        // Cascades to medications via patient_meal_cassette_id's FK
        // (cascadeOnDelete, see 2026_08_27_120100_...).
        DB::table('patient_meal_cassettes')->delete();

        $this->setMealEnum(self::NEW_MEALS);
    }

    public function down()
    {
        DB::table('patient_meal_cassettes')->delete();

        $this->setMealEnum(self::OLD_MEALS);
    }

    private function setMealEnum(array $meals)
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('patient_meal_cassettes', function (Blueprint $table) {
                $table->dropUnique(['patient_id', 'meal']);
                $table->dropColumn('meal');
            });
            // Nullable, not NOT NULL: SQLite's ADD COLUMN requires a default
            // whenever a new column is NOT NULL, even though the table is
            // empty at this point (the row count above doesn't matter to the
            // DDL itself). No real default value makes sense for an enum
            // with no natural "unset" meal, and app code (validation +
            // getOrCreateFor()) always supplies one on every real insert, so
            // nullable-only-in-this-driver's-schema is a fine trade for a
            // test database that gets rebuilt from migrations every run.
            Schema::table('patient_meal_cassettes', function (Blueprint $table) use ($meals) {
                $table->enum('meal', $meals)->nullable()->after('patient_id');
                $table->unique(['patient_id', 'meal']);
            });
            return;
        }

        $enumList = "'" . implode("','", $meals) . "'";
        DB::statement("ALTER TABLE patient_meal_cassettes MODIFY meal ENUM({$enumList}) NOT NULL");
    }
}
