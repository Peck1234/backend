<?php

namespace App\Http\Controllers;

use App\Mail\NursePasswordResetCodeMail;
use App\Models\Nurse;
use App\Models\PasswordResetCode;
use App\Support\NurseCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const RESET_CODE_TTL_MINUTES = 15;

    public function register(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:nurses,username|regex:/^[a-zA-Z0-9._-]+$/',
            'email' => 'required|string|email|max:255|unique:nurses,email',
            'password' => 'required|string|min:6',
        ]);

        $nurse = Nurse::create([
            'full_name' => $validated['full_name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'qr_code_nurse' => 'NURSE-PENDING-' . Str::uuid(),
        ]);

        $nurse->update(['qr_code_nurse' => NurseCodeGenerator::forId($nurse->id)]);

        $token = $nurse->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'nurse_id' => $nurse->id,
            'full_name' => $nurse->full_name,
            'username' => $nurse->username,
            'email' => $nurse->email,
            'qr_code_nurse' => $nurse->qr_code_nurse,
            'token' => $token,
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

        $token = $nurse->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'nurse_id' => $nurse->id,
            'full_name' => $nurse->full_name,
            'username' => $nurse->username,
            'email' => $nurse->email,
            'qr_code_nurse' => $nurse->qr_code_nurse,
            'token' => $token,
        ]);
    }

    // Always returns the same generic message whether or not the email is
    // registered - never reveal which emails exist in the system.
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
        ]);

        $nurse = Nurse::where('email', $validated['email'])->first();

        if ($nurse) {
            PasswordResetCode::where('email', $validated['email'])->delete();

            $code = (string) random_int(100000, 999999);

            PasswordResetCode::create([
                'email' => $validated['email'],
                'code' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::RESET_CODE_TTL_MINUTES),
            ]);

            Mail::to($validated['email'])->send(
                new NursePasswordResetCodeMail($code, self::RESET_CODE_TTL_MINUTES)
            );
        }

        return response()->json([
            'message' => 'หากอีเมลนี้มีอยู่ในระบบ เราได้ส่งรหัสยืนยันไปให้แล้ว',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'code' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        $genericError = response()->json([
            'detail' => 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ',
        ], 422);

        $candidates = PasswordResetCode::where('email', $validated['email'])
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get();

        $matched = $candidates->first(fn (PasswordResetCode $c) => Hash::check($validated['code'], $c->code));

        if (!$matched) {
            return $genericError;
        }

        $nurse = Nurse::where('email', $validated['email'])->first();

        if (!$nurse) {
            return $genericError;
        }

        $nurse->update(['password' => Hash::make($validated['new_password'])]);

        // Single-use: invalidate every outstanding code for this email, not
        // just the one that matched, so an older still-valid code can't be
        // replayed after a successful reset.
        PasswordResetCode::where('email', $validated['email'])->delete();

        return response()->json([
            'message' => 'ตั้งรหัสผ่านใหม่สำเร็จ',
        ]);
    }

    public function updateProfile(Request $request)
    {
        $nurse = $request->user();

        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'username' => 'required|string|max:255|regex:/^[a-zA-Z0-9._-]+$/|unique:nurses,username,' . $nurse->id,
            'email' => 'required|string|email|max:255|unique:nurses,email,' . $nurse->id,
        ]);

        $nurse->update($validated);

        return response()->json([
            'nurse_id' => $nurse->id,
            'full_name' => $nurse->full_name,
            'username' => $nurse->username,
            'email' => $nurse->email,
            'qr_code_nurse' => $nurse->qr_code_nurse,
        ]);
    }

    public function changePassword(Request $request)
    {
        $nurse = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        if (!Hash::check($validated['current_password'], $nurse->password)) {
            return response()->json([
                'detail' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            ], 422);
        }

        $nurse->update(['password' => Hash::make($validated['new_password'])]);

        return response()->json([
            'message' => 'เปลี่ยนรหัสผ่านสำเร็จ',
        ]);
    }
}
