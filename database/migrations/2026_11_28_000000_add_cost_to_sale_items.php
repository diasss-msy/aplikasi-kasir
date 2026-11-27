<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harga modal disimpan di tiap baris penjualan, supaya laba kotor laporan lama tidak ikut berubah
 * saat owner mengubah harga modal produk. Penjualan lama diisi dengan harga modal produk saat migrasi
 * dijalankan (harga modal saat terjual tidak tercatat sebelumnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', fn (Blueprint $t) => $t->unsignedInteger('cost')->default(0));
        DB::table('sale_items')->update([
            'cost' => DB::raw('(SELECT p.cost FROM products p WHERE p.id = sale_items.product_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn('cost'));
    }
};
