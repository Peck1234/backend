<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 'admin' may delete (patients, catalog medicines, drugs from a meal, slot
// assignments) and change other accounts' roles; 'nurse' dispenses only.
// Every existing and newly registered account starts as 'nurse' - the first
// admin is set with `php artisan nurse:make-admin <username>`.
class AddRoleToNursesTable extends Migration
{
    public function up()
    {
        Schema::table('nurses', function (Blueprint $table) {
            $table->string('role', 16)->default('nurse')->after('username');
        });
    }

    public function down()
    {
        Schema::table('nurses', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
}
