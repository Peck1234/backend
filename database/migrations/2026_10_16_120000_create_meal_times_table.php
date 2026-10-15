<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ward-wide clock time of each fixed meal round, editable by admins. A meal
// with no row here falls back to PatientMealCassette::MEAL_TIMES, so the table
// starts empty and only holds the rounds an admin has actually changed.
class CreateMealTimesTable extends Migration
{
    public function up()
    {
        Schema::create('meal_times', function (Blueprint $table) {
            $table->string('meal', 32)->primary();
            $table->string('time', 5);
            $table->foreignId('updated_by')->nullable()->constrained('nurses')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('meal_times');
    }
}
