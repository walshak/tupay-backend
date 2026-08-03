<?php

use App\Http\Controllers\LedgerController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\SwapController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Middleware\VerifyElevatedActionToken;
use App\Http\Middleware\VerifyWebhookSignature;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/


// 1. Webhook (No Auth, relies on Signature)
Route::post('/webhooks/settlement', [WebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class);
// 2. Login (Rate Limited to prevent brute force)
Route::post('/login', [LoginController::class, 'login'])
    ->middleware('throttle:5,1'); // 5 attempts per minute
// 3. Authenticated Endpoints
Route::middleware('auth:sanctum')->group(function () {

    // User profile
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    // Ledger History
    Route::get('/ledger/{wallet_id}', [LedgerController::class, 'index']);
    // Step-Up 2FA Challenge
    Route::post('/2fa/challenge', [TwoFactorController::class, 'challenge']);
    // The core swap engine (Requires EAT Token)
    Route::post('/swap', [SwapController::class, 'execute'])
        ->middleware(VerifyElevatedActionToken::class);
});
