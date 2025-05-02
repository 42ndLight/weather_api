<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WeatherController;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/weather', [WeatherController::class, 'index']);

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

