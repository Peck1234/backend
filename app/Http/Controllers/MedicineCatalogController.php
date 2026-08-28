<?php

namespace App\Http\Controllers;

use App\Imports\MedicineCatalogImport;
use App\Models\Medication;
use App\Models\MedicineCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class MedicineCatalogController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = MedicineCatalog::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('drug_name', 'like', "%{$search}%")
                    ->orWhere('generic_name', 'like', "%{$search}%");
            });
        }

        $medicines = $query
            ->orderByDesc('is_favorite')
            ->orderBy('drug_name')
            ->get();

        return response()->json(['medicines' => $medicines]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'drug_name' => 'required|string|max:255|unique:medicine_catalog,drug_name',
            'generic_name' => 'nullable|string|max:255',
            'standard_dose' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'contraindication' => 'nullable|string|max:255',
        ], [
            'drug_name.unique' => 'มียาชื่อนี้อยู่ในคลังยาแล้ว',
        ]);

        try {
            $medicine = MedicineCatalog::create($validated);
        } catch (QueryException $e) {
            return response()->json(['detail' => 'มียาชื่อนี้อยู่ในคลังยาแล้ว'], 422);
        }

        return response()->json($medicine, 201);
    }

    public function favorite(MedicineCatalog $medicine)
    {
        $medicine->is_favorite = !$medicine->is_favorite;
        $medicine->save();

        return response()->json($medicine);
    }

    // Removing a catalog entry never touches any patient's existing
    // medication rows - those keep their own copy of drug_name/standard_dose/
    // purpose (there is no medicine_catalog_id FK on medications at all; a
    // nurse assigning a drug just copies these fields at that moment). This
    // only takes the entry out of future autocomplete/search results.
    //
    // "In use" is necessarily a drug_name string match, not a real join -
    // the schema has nothing more precise to check against. drug_name is
    // unique in medicine_catalog, so a match is a reasonably strong signal,
    // just not a guaranteed one (a nurse could free-type an identical name
    // without ever touching the catalog).
    public function destroy(Request $request, MedicineCatalog $medicine)
    {
        $force = $request->boolean('force');

        $patientsUsingCount = Medication::where('drug_name', $medicine->drug_name)
            // whereHas('patient') implicitly respects Patient's SoftDeletes
            // scope - a patient who no longer exists shouldn't block this.
            ->whereHas('patient')
            ->distinct('patient_id')
            ->count('patient_id');

        if ($patientsUsingCount > 0 && !$force) {
            return response()->json([
                'detail' => "ยา \"{$medicine->drug_name}\" กำลังถูกใช้งานอยู่โดยผู้ป่วย {$patientsUsingCount} ราย ยืนยันการลบหรือไม่?",
                'needs_confirmation' => true,
                'patients_using_count' => $patientsUsingCount,
            ], 409);
        }

        $medicine->delete();

        return response()->json(['ok' => true]);
    }

    public function bulkImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120',
        ], [
            'file.required' => 'กรุณาแนบไฟล์',
            'file.mimes' => 'รองรับเฉพาะไฟล์ .xlsx, .xls หรือ .csv',
            'file.max' => 'ไฟล์ต้องมีขนาดไม่เกิน 5MB',
        ]);

        $import = new MedicineCatalogImport();
        Excel::import($import, $request->file('file'));

        if ($import->headerError !== null) {
            return response()->json(['detail' => $import->headerError], 422);
        }

        $existingNames = MedicineCatalog::pluck('drug_name')
            ->map(fn ($name) => mb_strtolower($name))
            ->flip();

        $toInsert = [];
        $errors = [];
        $seenInBatch = [];
        $now = now();

        foreach ($import->rows as $rowNumber => $data) {
            if ($data['drug_name'] === null) {
                $errors[] = ['row' => $rowNumber, 'reason' => 'ไม่ได้ระบุชื่อยา'];
                continue;
            }

            if ($data['standard_dose'] === null) {
                $errors[] = ['row' => $rowNumber, 'reason' => 'ไม่ได้ระบุขนาดมาตรฐาน'];
                continue;
            }

            $key = mb_strtolower($data['drug_name']);

            if ($existingNames->has($key)) {
                $errors[] = ['row' => $rowNumber, 'reason' => "\"{$data['drug_name']}\" มีอยู่ในคลังยาแล้ว"];
                continue;
            }

            if (isset($seenInBatch[$key])) {
                $errors[] = ['row' => $rowNumber, 'reason' => "\"{$data['drug_name']}\" ซ้ำกับแถวที่ {$seenInBatch[$key]} ในไฟล์เดียวกัน"];
                continue;
            }

            $seenInBatch[$key] = $rowNumber;

            $toInsert[] = [
                'drug_name' => $data['drug_name'],
                'generic_name' => $data['generic_name'] ?? null,
                'standard_dose' => $data['standard_dose'],
                'category' => $data['category'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'contraindication' => $data['contraindication'] ?? null,
                'is_favorite' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($toInsert)) {
            DB::transaction(function () use ($toInsert) {
                foreach (array_chunk($toInsert, 200) as $chunk) {
                    MedicineCatalog::insert($chunk);
                }
            });
        }

        return response()->json([
            'imported_count' => count($toInsert),
            'failed_count' => count($errors),
            'errors' => $errors,
        ]);
    }
}
