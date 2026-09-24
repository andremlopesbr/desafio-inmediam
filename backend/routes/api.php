<?php

use App\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['message' => 'ok']);
});

Route::get('/billing/{billing}', [BillingController::class, 'show']);
Route::post('/billing/{billing}/pay', [BillingController::class, 'pay']);
