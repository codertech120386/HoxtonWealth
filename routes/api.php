<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', PingController::class);

Route::post('/accounts', [AccountController::class, 'store']);
