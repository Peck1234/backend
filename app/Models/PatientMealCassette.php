<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// One row per (patient, meal) pair, created once via get-or-create the first
// time a drug is assigned to that meal. qr_code is permanent - it never
// changes for the lifetime of this cassette, only the medications attached
// to it change. 'prn' is a 5th pseudo-meal for as-needed medications that
// don't belong on any of the 4 fixed daily rounds.
class PatientMealCassette extends Model
{
    use HasFactory;

    public const MEALS = ['breakfast', 'lunch', 'dinner', 'bedtime', 'prn'];

    // Canonical clock time each fixed meal round happens at - used to derive
    // medications.time_slot for reminder scheduling. 'prn' deliberately has
    // no entry: it's never due at a fixed time, so it's excluded from
    // reminders entirely rather than assigned an arbitrary one.
    public const MEAL_TIMES = [
        'breakfast' => '08:00',
        'lunch' => '12:00',
        'dinner' => '18:00',
        'bedtime' => '21:00',
    ];

    protected $fillable = [
        'patient_id',
        'meal',
        'qr_code',
    ];

    // Same reason as Medication::$casts - the SQLite PDO driver (test suite)
    // returns non-primary-key INTEGER columns as strings, unlike MySQL
    // (production), which would make DispenseVerificationService's strict
    // patient_id comparison see "1" !== 1 and report a false mismatch.
    protected $casts = [
        'patient_id' => 'integer',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function medications()
    {
        return $this->hasMany(Medication::class);
    }

    // Shared by every place a medication gets attached to a meal
    // (PatientMealCassetteController's catalog-driven flow and
    // PatientController's own store/update) so the qr_code format and
    // get-or-create semantics can't drift between the two entry points.
    public static function getOrCreateFor(int $patientId, string $meal): self
    {
        return static::firstOrCreate(
            ['patient_id' => $patientId, 'meal' => $meal],
            ['qr_code' => "CASSETTE-{$patientId}-{$meal}"]
        );
    }
}
