<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'slot_code',
        'slot_no',
        'status',
        'current_patient_id',
        'created_by',
        'version',
        'occupied_at',
    ];

    protected $casts = [
        'occupied_at' => 'datetime',
    ];

    public function currentPatient()
    {
        return $this->belongsTo(Patient::class, 'current_patient_id');
    }

    public function creator()
    {
        return $this->belongsTo(Nurse::class, 'created_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(SlotAuditLog::class, 'slot_id');
    }
}
