<?php

use Illuminate\Support\Facades\Route;

# Healthcheck route for fleet
Route::get('/', function () {
    return view('welcome');
});
