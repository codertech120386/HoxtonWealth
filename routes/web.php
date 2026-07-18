<?php

use Illuminate\Support\Facades\Route;

# Healthcheck route for fleet host
Route::get('/', function () {
    return view('welcome');
});
