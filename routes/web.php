<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SavingsJourneyController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WebauthnController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/webauthn/authenticate/options', [WebauthnController::class, 'authenticateOptions']);
    Route::post('/webauthn/authenticate/verify', [WebauthnController::class, 'authenticateVerify']);
    Route::patch('/profile', [AuthController::class, 'updateProfile'])->middleware('auth');
    Route::delete('/profile', [AuthController::class, 'destroy'])->middleware('auth');
    Route::get('/data-summary', [AuthController::class, 'dataSummary'])->middleware('auth');
    Route::delete('/transactions/imported', [AuthController::class, 'deleteImportedTransactions'])->middleware('auth');

    Route::middleware('auth')->group(function () {
        Route::get('/webauthn/status', [WebauthnController::class, 'status']);
        Route::post('/webauthn/register/options', [WebauthnController::class, 'registerOptions']);
        Route::post('/webauthn/register/verify', [WebauthnController::class, 'registerVerify']);
        Route::delete('/webauthn', [WebauthnController::class, 'disable']);

        Route::post('/ai/monthly-reflection', [AiController::class, 'monthlyReflection']);
        Route::post('/ai/weekly-checkin', [AiController::class, 'weeklyCheckIn']);
        Route::post('/ai/chat', [AiController::class, 'chat']);

        Route::post('/statements/upload', [BankStatementController::class, 'upload']);
        Route::post('/statements/scan-images', [BankStatementController::class, 'scanImages']);
        Route::get('/statements/{import}', [BankStatementController::class, 'show']);
        Route::post('/statements/{import}/confirm', [BankStatementController::class, 'confirm']);
        Route::delete('/statements/{import}', [BankStatementController::class, 'destroy']);

        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::put('/transactions/{transaction}', [TransactionController::class, 'update']);
        Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);

        Route::get('/savings-journeys', [SavingsJourneyController::class, 'index']);
        Route::post('/savings-journeys', [SavingsJourneyController::class, 'store']);
        Route::patch('/savings-journeys/{journey}', [SavingsJourneyController::class, 'update']);
        Route::post('/savings-journeys/{journey}/add', [SavingsJourneyController::class, 'add']);
        Route::post('/savings-journeys/{journey}/pause', [SavingsJourneyController::class, 'pause']);
        Route::post('/savings-journeys/{journey}/resume', [SavingsJourneyController::class, 'resume']);
        Route::post('/savings-journeys/{journey}/complete', [SavingsJourneyController::class, 'complete']);

        Route::post('/receipts/scan', [ReceiptController::class, 'scan']);
        Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
        Route::post('/receipts/{receipt}/confirm', [ReceiptController::class, 'confirm']);
        Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy']);
    });
});

Route::get('/', function () {
    return auth()->check() ? redirect('/app') : view('app');
});
Route::view('/{any}', 'app')->where('any', '.*');
