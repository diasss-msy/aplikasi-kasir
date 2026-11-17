<?php

use App\Http\Controllers\KasirController;
use App\Http\Middleware\KasirAuth;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '\d{1,9}');

Route::controller(KasirController::class)->group(function () {
    Route::post('login', 'login');
    Route::post('logout', 'logout');

    Route::get('branches', 'branches')->middleware(KasirAuth::class); // semua peran yang sudah login

    // Kasir, admin, owner (termasuk PDA Barang Masuk, supaya stok masuk ke cabang yang benar). Karyawan stok tidak.
    Route::middleware(KasirAuth::class.':kasir,admin,owner')->group(function () {
        Route::get('products', 'products');
        Route::get('products/barcode/{code}', 'byBarcode')->where('code', '.*');
        Route::post('products', 'createProduct');
        Route::post('products/{id}/barcodes', 'linkBarcode');
        Route::post('receipts', 'receive');
        Route::get('report', 'report');
        Route::get('daily-reports', 'dailyReports');
    });

    // Karyawan stok: hanya melihat barang menipis/habis per cabang (admin & owner juga boleh)
    Route::get('stock-alerts', 'stockAlerts')->middleware(KasirAuth::class.':stok,admin,owner');

    // Hanya owner: katalog dan harga berlaku untuk semua cabang
    Route::middleware(KasirAuth::class.':owner')->group(function () {
        Route::put('products/{id}', 'updateProduct');
        Route::put('products/{id}/price', 'branchPrice');
        Route::delete('products/{id}', 'deleteProduct');
        Route::post('products/{id}/keep', 'keepProduct');
    });

    // Hanya kasir, di cabangnya sendiri
    Route::middleware(KasirAuth::class.':kasir')->group(function () {
        Route::post('sales', 'sell');
        Route::post('daily-reports', 'uploadDaily');
    });
});
