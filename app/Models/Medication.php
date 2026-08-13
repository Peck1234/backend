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
        'drug_name',
        'standard_dose',
        'purpose',
        'dose',
        'instruction',
        'time_slot',
        'qr_code_cassette',
        'dispensed_at',
    ];

    protected $casts = [
        'dispensed_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
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
