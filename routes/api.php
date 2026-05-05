<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\TransferController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', PingController::class);

Route::post('/accounts', [AccountController::class, 'store']);
Route::get('/accounts/{id}', [AccountController::class, 'show']);
Route::get('/accounts/{id}/ledger', [LedgerController::class, 'index']);
Route::post('/accounts/{id}/deposits', [DepositController::class, 'store']);

Route::post('/transfers', [TransferController::class, 'store'])->middleware('throttle:transfers');
Route::get('/transfers/{id}', [TransferController::class, 'show']);
