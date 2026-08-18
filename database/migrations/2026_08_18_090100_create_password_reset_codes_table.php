<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deliberately its own table rather than reusing the framework's default
// password_resets table (that one is shaped around a long opaque token for
// email-link resets and isn't tied to the Nurse model) - this one stores a
// short numeric code with its own explicit expiry, matching the code-entry
// (not click-a-link) reset flow the app actually uses.
class CreatePasswordResetCodesTable extends Migration
{
    public function up()
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('code');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('email');
        });
    }

    public function down()
    {
        Schema::dropIfExists('password_reset_codes');
    }
}
