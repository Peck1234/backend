<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'full_name',
        'ward',
        'bed_no',
        'qr_code_patient',
    ];

    public function medications()
    {
        return $this->hasMany(Medication::class);
    }

    public function mealCassettes()
    {
        return $this->hasMany(PatientMealCassette::class);
    }

    public function cartSlot()
    {
        return $this->hasOne(CartSlot::class, 'current_patient_id');
    }
}
