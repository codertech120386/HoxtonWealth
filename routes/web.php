<?php

use Illuminate\Support\Facades\Route;

# Healthcheck route for fleet hosting service
Route::get('/', function () {
    return "testing to check 200's work immediately";
});
