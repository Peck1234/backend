<?php

namespace App\Http\Controllers;

use App\Models\Nurse;
use App\Support\NurseCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:nurses,username|regex:/^[a-zA-Z0-9._-]+$/',
            'password' => 'required|string|min:6',
        ]);

        $nurse = Nurse::create([
            'full_name' => $validated['full_name'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'qr_code_nurse' => 'NURSE-PENDING-' . Str::uuid(),
        ]);

        $nurse->update(['qr_code_nurse' => NurseCodeGenerator::forId($nurse->id)]);

        return response()->json([
            'nurse_id' => $nurse->id,
            'full_name' => $nurse->full_name,
            'username' => $nurse->username,
            'qr_code_nurse' => $nurse->qr_code_nurse,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $nurse = Nurse::where('username', $credentials['username'])->first();

        if (!$nurse || !Hash::check($credentials['password'], $nurse->password)) {
            return response()->json([
                'detail' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
            ], 401);
        }

        return response()->json([
            'nurse_id' => $nurse->id,
            'full_name' => $nurse->full_name,
            'username' => $nurse->username,
            'qr_code_nurse' => $nurse->qr_code_nurse,
        ]);
    }
}
