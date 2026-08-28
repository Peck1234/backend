<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deleting a patient must never remove their medication/dispense history
// (medications.patient_id and dispense_logs.patient_id stay intact either
// way) - soft delete is what actually satisfies "still queryable by anyone
// who explicitly asks", while keeping every normal index()/find() query
// (which doesn't call withTrashed()) automatically excluding them, for free,
// via Eloquent's SoftDeletingScope.
class AddDeletedAtToPatientsTable extends Migration
{
    public function up()
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
