<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(public_path('kasir.html')));
Route::get('/pda', fn () => response()->file(public_path('pda.html')));
