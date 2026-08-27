<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A cassette QR is now permanent per (patient, meal) pair, not per drug - it
// gets created once (get-or-create) the first time a medication is assigned
// to that meal, and stays valid no matter how many drugs are added/removed
// from inside it afterward. 'prn' is a 5th pseudo-meal for medications given
// "as needed" rather than on the 4 fixed daily rounds - see the data
// migration in the next migration for why this bucket exists.
class CreatePatientMealCassettesTable extends Migration
{
    public function up()
    {
        Schema::create('patient_meal_cassettes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->enum('meal', ['breakfast', 'lunch', 'dinner', 'bedtime', 'prn']);
            $table->string('qr_code')->unique();
            $table->timestamps();

            $table->unique(['patient_id', 'meal']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('patient_meal_cassettes');
    }
}
