<?php

namespace App\Console\Commands;

use App\Models\CartSlot;
use App\Models\Patient;
use App\Models\SlotAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillCartSlots extends Command
{
    protected $signature = 'slots:backfill';

    protected $description = 'Migrate legacy patients.qr_code_slot/slot_no into cart_slots (idempotent).';

    public function handle()
    {
        $dupes = Patient::whereNotNull('qr_code_slot')->get()
            ->groupBy('qr_code_slot')
            ->filter(fn ($g) => $g->count() > 1);

        if ($dupes->isNotEmpty()) {
            $this->error('Duplicate qr_code_slot values found — aborting. Resolve manually first:');
            foreach ($dupes as $code => $patients) {
                $this->line("  {$code}: patient ids " . $patients->pluck('id')->implode(', '));
            }
            return 1;
        }

        $created = 0;
        $skipped = 0;

        Patient::whereNotNull('qr_code_slot')->chunk(50, function ($patients) use (&$created, &$skipped) {
            foreach ($patients as $patient) {
                $slot = CartSlot::firstOrCreate(
                    ['slot_code' => $patient->qr_code_slot],
                    [
                        'slot_no' => $patient->slot_no,
                        'status' => 'occupied',
                        'current_patient_id' => $patient->id,
                        'occupied_at' => $patient->created_at,
                        'version' => 1,
                    ]
                );

                if (!$slot->wasRecentlyCreated) {
                    $skipped++;
                    continue;
                }

                SlotAuditLog::create([
                    'client_log_id' => (string) Str::uuid(),
                    'slot_id' => $slot->id,
                    'staff_id' => null,
                    'action' => 'create',
                    'before' => null,
                    'after' => [
                        'slot_code' => $slot->slot_code,
                        'migrated_from' => 'patients.qr_code_slot',
                        'patient_id' => $patient->id,
                    ],
                    'occurred_at' => $patient->created_at ?? now(),
                    'synced_at' => now(),
                ]);

                $created++;
            }
        });

        $this->info("Backfill done. Created: {$created}, already existed (skipped): {$skipped}");

        return 0;
    }
}
