<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deleting an account is a soft delete: it can't log in and drops out of
// lists, but dispense logs and slot audit logs keep pointing at it, so the
// medication record still shows who gave each dose.
class AddDeletedAtToNursesTable extends Migration
{
    public function up()
    {
        Schema::table('nurses', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::table('nurses', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
