<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashiers', function (Blueprint $t) {
            $t->string('role', 10)->default('kasir'); // kasir | admin | owner | stok
        });
        DB::table('cashiers')->where('name', 'Admin')->update(['role' => 'admin']);

        // Laporan penjualan harian yang dikirim kasir: satu baris per tanggal, kirim ulang menimpa.
        Schema::create('daily_reports', function (Blueprint $t) {
            $t->id();
            $t->date('date')->unique();
            $t->foreignId('cashier_id')->constrained();
            $t->dateTime('created_at');
            $t->unsignedInteger('count');
            $t->unsignedInteger('items');
            $t->unsignedBigInteger('revenue');
            $t->unsignedBigInteger('cash'); // bagian Tunai dari revenue = uang yang harus disetor
            $t->bigInteger('profit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
        Schema::table('cashiers', function (Blueprint $t) {
            $t->dropColumn('role');
        });
    }
};
