<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

class Medication extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'patient_meal_cassette_id',
        'drug_name',
        'standard_dose',
        'purpose',
        'dose',
        'instruction',
        'time_slot',
        'qr_code_cassette',
        'dispensed_at',
    ];

    // 'patient_id'/'patient_meal_cassette_id' are explicitly cast for the same
    // reason as CartSlot::$version - PHP's SQLite PDO driver (used by the test
    // suite) returns non-primary-key INTEGER columns as strings, unlike the
    // MySQL driver used in production, which would make strict comparisons
    // (DispenseVerificationService's patient_id check, PatientMealCassetteController's
    // ownership checks) see e.g. "1" !== 1 and report a false mismatch.
    protected $casts = [
        'dispensed_at' => 'datetime',
        'patient_id' => 'integer',
        'patient_meal_cassette_id' => 'integer',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function mealCassette()
    {
        return $this->belongsTo(PatientMealCassette::class, 'patient_meal_cassette_id');
    }

    public function matchedDueTime(): ?string
    {
        if (!$this->time_slot) {
            return null;
        }

        $now = Date::now()->format('H:i');

        return collect(explode(',', $this->time_slot))
            ->first(fn ($slot) => $slot <= $now);
    }

    public function isDispensedToday(): bool
    {
        return $this->dispensed_at !== null && $this->dispensed_at->isToday();
    }

    public function isDueNow(): bool
    {
        return !$this->isDispensedToday() && !is_null($this->matchedDueTime());
    }
}
