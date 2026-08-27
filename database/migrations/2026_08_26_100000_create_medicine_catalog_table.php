<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMedicineCatalogTable extends Migration
{
    public function up()
    {
        Schema::create('medicine_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('drug_name')->unique();
            $table->string('generic_name')->nullable();
            $table->string('standard_dose')->nullable();
            $table->string('category')->nullable();
            $table->string('purpose')->nullable();
            $table->string('contraindication')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('medicine_catalog');
    }
}
