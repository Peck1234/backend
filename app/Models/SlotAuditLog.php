<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class SlotAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_log_id',
        'slot_id',
        'staff_id',
        'action',
        'before',
        'after',
        'occurred_at',
        'synced_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'occurred_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    // Append-only: history must never change once written.
    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException('slot_audit_logs is append-only — update() is not allowed.');
    }

    public function delete()
    {
        throw new RuntimeException('slot_audit_logs is append-only — delete() is not allowed.');
    }

    public function slot()
    {
        return $this->belongsTo(CartSlot::class, 'slot_id');
    }

    public function staff()
    {
        return $this->belongsTo(Nurse::class, 'staff_id');
    }
}
