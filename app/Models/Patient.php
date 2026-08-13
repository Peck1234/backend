<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use HasFactory;

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

    public function cartSlot()
    {
        return $this->hasOne(CartSlot::class, 'current_patient_id');
    }
}
