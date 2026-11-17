<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Produk yang dihapus saat stoknya masih ada: tetap dijual, lalu otomatis terhapus begitu stoknya habis.
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('delete_when_empty')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('delete_when_empty');
        });
    }
};
