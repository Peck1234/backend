<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispenseController;
use App\Http\Controllers\DispenseLogController;
use App\Http\Controllers\MedicineCatalogController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\SlotController;
use App\Http\Controllers\StatsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Deliberately no DB query — this is the liveness check start-backend.ps1 and
// watch-backend.ps1 poll (both locally and through the public tunnel URL) to
// confirm the backend is actually serving requests, not just that the process
// exists. Keeping it DB-free means it still correctly reports "the web server
// itself is up" even if the database connection is the thing that's broken,
// which is a genuinely different failure mode worth being able to tell apart.
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
});

Route::get('/patients', [PatientController::class, 'index']);
Route::post('/patients', [PatientController::class, 'store']);
Route::put('/patients/{patient}', [PatientController::class, 'update']);
Route::get('/patients/{patient}/medications', [PatientController::class, 'medications']);
Route::post('/verify-dispense', [DispenseController::class, 'verify']);
Route::get('/due-medications', [ReminderController::class, 'due']);
Route::get('/reminder-times', [ReminderController::class, 'scheduleTimes']);
Route::get('/dispense-stats', [StatsController::class, 'index']);
Route::get('/dispense-logs', [DispenseLogController::class, 'index']);
Route::get('/slots', [SlotController::class, 'index']);
Route::post('/slots', [SlotController::class, 'store']);
Route::put('/slots/{slot}/assign', [SlotController::class, 'assign']);
Route::put('/slots/{slot}/clear', [SlotController::class, 'clear']);
Route::get('/slots/{slot}/history', [SlotController::class, 'history']);

Route::get('/medicine-catalog', [MedicineCatalogController::class, 'index']);
Route::post('/medicine-catalog', [MedicineCatalogController::class, 'store']);
Route::put('/medicine-catalog/{medicine}/favorite', [MedicineCatalogController::class, 'favorite']);
Route::post('/medicine-catalog/bulk-import', [MedicineCatalogController::class, 'bulkImport']);
