<?php

namespace App\Http\Controllers;

use App\Models\Nurse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Admin-only: list accounts and change who is an admin.
class NurseRoleController extends Controller
{
    public function index()
    {
        return response()->json(
            Nurse::orderByRaw("role = 'admin' desc")->orderBy('full_name')->get()
                ->map(fn (Nurse $n) => [
                    'nurse_id' => $n->id,
                    'full_name' => $n->full_name,
                    'username' => $n->username,
                    'role' => $n->role,
                ])
                ->values()
        );
    }

    // Closes an account: it can no longer log in, any device logged in with it
    // is signed out, and its username/email are freed for a new account. The
    // row itself stays (soft delete) so dispense history keeps the name.
    public function destroy(Request $request, Nurse $nurse)
    {
        if ($nurse->id === $request->user()->id) {
            return response()->json(['detail' => 'ไม่สามารถลบบัญชีของตัวเองได้'], 422);
        }

        $nurse->tokens()->delete();
        $nurse->forceFill([
            'username' => "{$nurse->username}#deleted-{$nurse->id}",
            'email' => null,
        ])->save();
        $nurse->delete();

        return response()->json(['message' => 'ลบบัญชีแล้ว']);
    }

    public function update(Request $request, Nurse $nurse)
    {
        $data = $request->validate(
            ['role' => ['required', Rule::in(Nurse::ROLES)]],
            ['role.*' => 'บทบาทไม่ถูกต้อง']
        );

        if ($nurse->id === $request->user()->id && $data['role'] !== 'admin') {
            return response()->json(['detail' => 'ไม่สามารถถอดสิทธิ์ผู้ดูแลของตัวเองได้'], 422);
        }

        $nurse->update(['role' => $data['role']]);

        return response()->json([
            'nurse_id' => $nurse->id,
            'full_name' => $nurse->full_name,
            'username' => $nurse->username,
            'role' => $nurse->role,
        ]);
    }
}
