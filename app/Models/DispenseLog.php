<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DispenseLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'medication_id',
        'patient_id',
        'nurse_id',
        'cassette_qr',
        'result',
        'message',
    ];

    public function medication()
    {
        return $this->belongsTo(Medication::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function nurse()
    {
        return $this->belongsTo(Nurse::class);
    }
}
