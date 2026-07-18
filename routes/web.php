<?php

use Illuminate\Support\Facades\Route;

# Healthcheck route for fleet hosting service
Route::get('/', function () {
    return "testing again after the fix";
});
