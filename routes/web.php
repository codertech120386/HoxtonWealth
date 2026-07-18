<?php

use Illuminate\Support\Facades\Route;

# Healthcheck route for any deployment service
Route::get('/', function () {
    return view('welcome');
});
