<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProfilePhotoPathToNursesTable extends Migration
{
    public function up()
    {
        Schema::table('nurses', function (Blueprint $table) {
            $table->string('profile_photo_path')->nullable()->after('qr_code_nurse');
        });
    }

    public function down()
    {
        Schema::table('nurses', function (Blueprint $table) {
            $table->dropColumn('profile_photo_path');
        });
    }
}
