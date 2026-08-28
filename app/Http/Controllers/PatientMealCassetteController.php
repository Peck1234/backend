<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientMealCassetteController extends Controller
{
    // Lists this patient's cassettes (only meals that actually have at least
    // one medication - a get-or-create never happens speculatively) with
    // their medications nested, for PatientQrCodesScreen and any other
    // "show me this patient's cassettes" view.
    public function index(Patient $patient)
    {
        $cassettes = $patient->mealCassettes()
            ->with('medications')
            ->get()
            ->sortBy(fn ($c) => array_search($c->meal, PatientMealCassette::MEALS))
            ->values()
            ->map(fn (PatientMealCassette $cassette) => $this->cassetteResponse($cassette));

        return response()->json(['cassettes' => $cassettes]);
    }

    // The core new flow: "pick a drug from the catalog, pick a patient, pick
    // a meal" in one call. Get-or-creates the (patient, meal) cassette - its
    // qr_code is permanent, so a second drug assigned to a meal that already
    // has a cassette reuses the exact same QR rather than minting a new one.
    public function assignMedicine(Request $request, Patient $patient)
    {
        $validated = $request->validate([
            'meal' => 'required|in:' . implode(',', PatientMealCassette::MEALS),
            'drug_name' => 'required|string|max:255',
            'standard_dose' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'dose' => 'required|string|max:255',
            'instruction' => 'nullable|string|max:255',
        ]);

        [$cassette, $isNew] = DB::transaction(function () use ($patient, $validated) {
            $cassette = PatientMealCassette::getOrCreateFor($patient->id, $validated['meal']);
            // Captured immediately - ->fresh()/->load() below would otherwise
            // silently lose it (a re-queried/reloaded instance never went
            // through the "just inserted" code path, so wasRecentlyCreated
            // reads false on it regardless of whether the row is actually new).
            $isNew = $cassette->wasRecentlyCreated;

            $cassette->medications()->create([
                'patient_id' => $patient->id,
                'drug_name' => $validated['drug_name'],
                'standard_dose' => $validated['standard_dose'] ?? null,
                'purpose' => $validated['purpose'] ?? null,
                'dose' => $validated['dose'],
                'instruction' => $validated['instruction'] ?? null,
                'time_slot' => PatientMealCassette::MEAL_TIMES[$validated['meal']] ?? null,
            ]);

            return [$cassette, $isNew];
        });

        // is_new reflects the cassette itself (not the medication) - exactly
        // the "was a brand-new QR just minted, or did this reuse an existing
        // one" signal the frontend needs to decide whether to show the
        // print/save-QR screen.
        $cassette->load('medications');
        return response()->json($this->cassetteResponse($cassette, $isNew), 201);
    }

    // Single-cassette detail view - lets the nurse open one QR card (from
    // PatientQrCodesScreen) and see exactly what's inside it, without
    // fetching every cassette the patient has via index().
    public function show(Patient $patient, PatientMealCassette $cassette)
    {
        abort_unless($cassette->patient_id === $patient->id, 404);

        return response()->json($this->cassetteResponse($cassette->load('medications')));
    }

    // Edits one drug already inside a cassette - deliberately scoped to just
    // this medication (unlike PatientController::update(), which treats its
    // whole `medications` payload as authoritative and deletes anything
    // omitted). Does not touch `meal`/which cassette this drug belongs to -
    // moving a drug between meals stays in the full patient-edit flow.
    public function updateMedicine(Request $request, Patient $patient, PatientMealCassette $cassette, Medication $medication)
    {
        abort_unless($cassette->patient_id === $patient->id, 404);
        abort_unless($medication->patient_meal_cassette_id === $cassette->id, 404);

        $validated = $request->validate([
            'drug_name' => 'required|string|max:255',
            'standard_dose' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'dose' => 'required|string|max:255',
            'instruction' => 'nullable|string|max:255',
        ]);

        $medication->update($validated);

        return response()->json($this->cassetteResponse($cassette->fresh('medications')));
    }

    // Removes one drug from a cassette - the cassette itself (and its QR)
    // stays even if this empties it out, since the QR is permanent per the
    // architecture (a nurse may add a different drug back into it later).
    public function removeMedicine(Patient $patient, PatientMealCassette $cassette, Medication $medication)
    {
        abort_unless($cassette->patient_id === $patient->id, 404);
        abort_unless($medication->patient_meal_cassette_id === $cassette->id, 404);

        $medication->delete();

        return response()->json($this->cassetteResponse($cassette->fresh('medications')));
    }

    private function cassetteResponse(PatientMealCassette $cassette, ?bool $isNew = null)
    {
        return [
            'cassette_id' => $cassette->id,
            'meal' => $cassette->meal,
            'qr_code' => $cassette->qr_code,
            'is_new' => $isNew ?? $cassette->wasRecentlyCreated,
            'medications' => $cassette->medications->map(fn ($m) => [
                'order_id' => $m->id,
                'drug_name' => $m->drug_name,
                'standard_dose' => $m->standard_dose,
                'purpose' => $m->purpose,
                'dose' => $m->dose,
                'instruction' => $m->instruction,
                'is_dispensed_today' => $m->isDispensedToday(),
            ]),
        ];
    }
}
