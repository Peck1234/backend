<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispenseController;
use App\Http\Controllers\DispenseLogController;
use App\Http\Controllers\MealTimeController;
use App\Http\Controllers\MedicineCatalogController;
use App\Http\Controllers\NurseRoleController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientDispenseHistoryController;
use App\Http\Controllers\PatientMealCassetteController;
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
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/profile',[AuthController::class, 'updateProfile']);
    Route::post('/profile/photo', [AuthController::class, 'uploadProfilePhoto']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/patients/{patient}/dispense-history', [PatientDispenseHistoryController::class, 'index']);
    Route::get('/patients/{patient}/dispense-history/export', [PatientDispenseHistoryController::class, 'export']);
});

// Admin only: deleting, and managing who is an admin.
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/nurses', [NurseRoleController::class, 'index']);
    Route::put('/meal-times', [MealTimeController::class, 'update']);
    Route::put('/nurses/{nurse}/role',[NurseRoleController::class, 'update']);
    Route::delete('/nurses/{nurse}', [NurseRoleController::class, 'destroy']);
    Route::delete('/patients/{patient}', [PatientController::class, 'destroy']);
    Route::delete('/patients/{patient}/meal-cassettes/{cassette}/medications/{medication}', [PatientMealCassetteController::class, 'removeMedicine']);
    Route::put('/slots/{slot}/clear', [SlotController::class, 'clear']);
    Route::delete('/medicine-catalog/{medicine}', [MedicineCatalogController::class, 'destroy']);
});

Route::get('/patients', [PatientController::class, 'index']);
Route::post('/patients', [PatientController::class, 'store']);
Route::put('/patients/{patient}', [PatientController::class, 'update']);
Route::get('/patients/{patient}/medications', [PatientController::class, 'medications']);
Route::get('/patients/{patient}/meal-cassettes', [PatientMealCassetteController::class, 'index']);
Route::get('/patients/{patient}/meal-cassettes/{cassette}', [PatientMealCassetteController::class, 'show']);
Route::post('/patients/{patient}/meal-cassettes/assign-medicine', [PatientMealCassetteController::class, 'assignMedicine']);
Route::put('/patients/{patient}/meal-cassettes/{cassette}/medications/{medication}', [PatientMealCassetteController::class, 'updateMedicine']);
Route::post('/verify-cassette', [DispenseController::class, 'verifyCassette']);
Route::post('/dispense-medications', [DispenseController::class, 'dispenseMedications']);
Route::get('/due-medications', [ReminderController::class, 'due']);
Route::get('/meal-times', [MealTimeController::class, 'index']);
Route::get('/reminder-times',[ReminderController::class, 'scheduleTimes']);
Route::get('/reminder-schedule', [ReminderController::class, 'scheduleItems']);
Route::get('/dispense-stats', [StatsController::class, 'index']);
Route::get('/dispense-logs', [DispenseLogController::class, 'index']);
Route::get('/slots', [SlotController::class, 'index']);
Route::post('/slots', [SlotController::class, 'store']);
Route::put('/slots/{slot}/assign', [SlotController::class, 'assign']);
Route::get('/slots/{slot}/history', [SlotController::class, 'history']);

Route::get('/medicine-catalog', [MedicineCatalogController::class, 'index']);
Route::post('/medicine-catalog', [MedicineCatalogController::class, 'store']);
Route::put('/medicine-catalog/{medicine}/favorite', [MedicineCatalogController::class, 'favorite']);
Route::post('/medicine-catalog/bulk-import', [MedicineCatalogController::class, 'bulkImport']);
