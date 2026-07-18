<?php

use Illuminate\Support\Facades\Route;

# Healthcheck route for fleet hosting service
Route::get('/', function () {
    return "I am now on fleet";
});
