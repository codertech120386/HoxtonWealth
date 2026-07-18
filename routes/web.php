<?php

use Illuminate\Support\Facades\Route;

# Healthcheck route
Route::get('/', function () {
    return view('welcome');
});
