<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Not run automatically — see slots:backfill and the cart_slots/slot_audit_logs
// migration first. Every patient that had qr_code_slot/slot_no has already been
// backfilled into cart_slots (verified via GET /api/slots), and PatientController
// now derives slot info from that relation instead of these columns, so this is
// safe to run once you've confirmed the app works end-to-end.
class DropLegacySlotFieldsFromPatientsTable extends Migration
{
    public function up()
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique(['qr_code_slot']);
            $table->dropColumn(['slot_no', 'qr_code_slot']);
        });
    }

    public function down()
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->integer('slot_no')->nullable()->after('bed_no');
            $table->string('qr_code_slot')->nullable()->unique()->after('qr_code_patient');
        });
    }
}
