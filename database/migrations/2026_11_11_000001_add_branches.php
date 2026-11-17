<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banyak cabang: katalog produk tetap satu (dikelola owner), tetapi stok dan harga jual per cabang.
 * Semua data lama dipindah ke "Cabang 1".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('name', 40)->unique();
        });
        $b1 = DB::table('branches')->insertGetId(['name' => 'Cabang 1']);

        // Baris dibuat saat pertama dibutuhkan; tidak ada baris = stok 0 dan harga standar.
        Schema::create('branch_products', function (Blueprint $t) {
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->unsignedInteger('stock')->default(0);
            $t->unsignedInteger('price')->nullable(); // null = ikut harga standar produk
            $t->primary(['branch_id', 'product_id']);
        });
        foreach (DB::table('products')->get(['id', 'stock']) as $p) {
            DB::table('branch_products')->insert(['branch_id' => $b1, 'product_id' => $p->id, 'stock' => $p->stock]);
        }
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('stock'));

        // Kasir terikat satu cabang; admin & owner (null) melihat semua cabang.
        Schema::table('cashiers', fn (Blueprint $t) => $t->foreignId('branch_id')->nullable()->constrained());
        DB::table('cashiers')->where('role', 'kasir')->update(['branch_id' => $b1]);

        foreach (['sales', 'receipts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('branch_id')->nullable()->constrained());
            DB::table($table)->update(['branch_id' => $b1]);
        }

        // Laporan harian: satu per tanggal per cabang.
        Schema::table('daily_reports', function (Blueprint $t) {
            $t->dropUnique(['date']);
            $t->foreignId('branch_id')->nullable()->constrained();
        });
        DB::table('daily_reports')->update(['branch_id' => $b1]);
        Schema::table('daily_reports', fn (Blueprint $t) => $t->unique(['date', 'branch_id']));
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $t) {
            $t->dropUnique(['date', 'branch_id']);
            $t->dropConstrainedForeignId('branch_id');
            $t->unique('date');
        });
        foreach (['sales', 'receipts', 'cashiers'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
        }
        Schema::table('products', fn (Blueprint $t) => $t->unsignedInteger('stock')->default(0));
        foreach (DB::table('branch_products')->selectRaw('product_id, SUM(stock) AS s')->groupBy('product_id')->get() as $r) {
            DB::table('products')->where('id', $r->product_id)->update(['stock' => (int) $r->s]);
        }
        Schema::dropIfExists('branch_products');
        Schema::dropIfExists('branches');
    }
};
