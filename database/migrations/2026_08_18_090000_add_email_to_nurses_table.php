<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nullable at the DB level on purpose - existing nurse rows have no email and
// this migration must not fail against them. AuthController::register()
// enforces "required" going forward at the application/validation level.
class AddEmailToNursesTable extends Migration
{
    public function up()
    {
        Schema::table('nurses', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('username');
        });
    }

    public function down()
    {
        Schema::table('nurses', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
}
