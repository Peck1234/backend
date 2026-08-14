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

    // 'version' is explicitly cast because PHP's SQLite PDO driver (used by
    // the test suite) returns INTEGER columns as strings, unlike the MySQL
    // driver Laravel connects with in production - without this cast,
    // SlotAssignmentService's strict version comparison would see "3" !== 3
    // and report a false version_conflict under SQLite only.
    protected $casts = [
        'occupied_at' => 'datetime',
        'version' => 'integer',
        'slot_no' => 'integer',
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
