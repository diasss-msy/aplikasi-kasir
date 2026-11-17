<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 20)->nullable();
            $t->string('name', 80);
            $t->string('category', 40);
            $t->string('unit', 20)->default('pcs');
            $t->unsignedInteger('price');
            $t->unsignedInteger('cost')->default(0);
            $t->unsignedInteger('stock')->default(0);
            $t->boolean('active')->default(true); // hapus lunak: riwayat penjualan tetap utuh
        });

        Schema::create('barcodes', function (Blueprint $t) {
            $t->string('code', 32)->primary();
            $t->foreignId('product_id')->constrained();
        });

        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->string('number', 24)->unique();
            $t->dateTime('created_at');
        });

        Schema::create('receipt_items', function (Blueprint $t) {
            $t->foreignId('receipt_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->unsignedInteger('qty');
            $t->unsignedInteger('stock_before');
            $t->unsignedInteger('stock_after');
        });

        // Akun kasir (login PIN). Terpisah dari tabel `users` bawaan Laravel.
        Schema::create('cashiers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 40)->unique();
            $t->string('pin_hash');
        });

        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->string('number', 24)->unique();
            $t->dateTime('created_at')->index();
            $t->foreignId('cashier_id')->constrained();
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('tax');
            $t->unsignedBigInteger('total');
            $t->string('method', 20);
            $t->unsignedBigInteger('paid');
            $t->unsignedBigInteger('change_due');
        });

        Schema::create('sale_items', function (Blueprint $t) {
            $t->foreignId('sale_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->string('name', 80);
            $t->unsignedInteger('price');
            $t->unsignedInteger('qty');
        });
    }

    public function down(): void
    {
        foreach (['sale_items', 'sales', 'cashiers', 'receipt_items', 'receipts', 'barcodes', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
