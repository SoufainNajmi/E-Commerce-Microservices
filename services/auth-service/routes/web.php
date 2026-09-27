<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['service' => 'auth-service', 'status' => 'healthy']));
