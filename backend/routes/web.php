<?php

use App\Http\Controllers\Api\V1\PublicTableController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/order/{token}', [PublicTableController::class, 'landingPage'])->name('public.order');
