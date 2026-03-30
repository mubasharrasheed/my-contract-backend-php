<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

// public auth routes
Route::post('register', [\App\Http\Controllers\AuthController::class, 'register']);
Route::post('login', [\App\Http\Controllers\AuthController::class, 'login']);
Route::post('forgot-password', [\App\Http\Controllers\AuthController::class, 'forgotPassword']);
Route::post('reset-password', [\App\Http\Controllers\AuthController::class, 'resetPassword']);
Route::post('verify-pin', [\App\Http\Controllers\AuthController::class, 'verifyPin']);

// protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('logout', [\App\Http\Controllers\AuthController::class, 'logout']);

    // Contracts: create (PDF upload) + index/show; documents are linked via contract
    Route::post('contracts', [\App\Http\Controllers\ContractController::class, 'create']);
    Route::get('contracts', [\App\Http\Controllers\ContractController::class, 'index']);
    Route::get('contracts/{contract}', [\App\Http\Controllers\ContractController::class, 'show']);
});
