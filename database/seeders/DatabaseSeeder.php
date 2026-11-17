<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    // [barcode, sku, nama, kategori, satuan, harga jual, harga modal, stok di cabang pertama]
    const PRODUCTS = [
        ['8991234500010', 'MKN-001', 'Nasi Goreng Spesial', 'Makanan', 'porsi', 25000, 14000, 24],
        ['8991234500027', 'MKN-002', 'Mie Ayam Bakso', 'Makanan', 'porsi', 22000, 12000, 18],
        ['8991234500034', 'MKN-003', 'Ayam Geprek', 'Makanan', 'porsi', 20000, 11000, 30],
        ['8991234500041', 'MKN-004', 'Soto Ayam', 'Makanan', 'porsi', 18000, 9500, 12],
        ['8991234500058', 'MNM-005', 'Es Teh Manis', 'Minuman', 'gelas', 5000, 1500, 80],
        ['8991234500065', 'MNM-006', 'Kopi Susu Gula Aren', 'Minuman', 'pcs', 18000, 8500, 40],
        ['8991234500072', 'MNM-007', 'Jus Alpukat', 'Minuman', 'gelas', 15000, 7000, 0],
        ['8991234500089', 'MNM-008', 'Air Mineral 600ml', 'Minuman', 'botol', 4000, 2200, 120],
        ['8991234500096', 'SNK-009', 'Keripik Singkong', 'Snack', 'pcs', 10000, 6000, 35],
        ['8991234500102', 'SNK-010', 'Roti Bakar Coklat', 'Snack', 'pcs', 15000, 7500, 5],
        ['8991234500119', 'SNK-011', 'Pisang Goreng', 'Snack', 'pcs', 12000, 6000, 3],
        ['8991234500126', 'KRT-002', 'Sabun Cuci Piring', 'Kebutuhan Rumah', 'pcs', 14500, 11000, 25],
    ];

    // Akun awal [nama, PIN, peran]. Ganti PIN-nya setelah dipasang: php artisan kasir:pin
    const CASHIERS = [['Kasir 1', '111111', 'kasir'], ['Admin', '123456', 'admin'], ['Owner', '888888', 'owner'], ['Staf Stok', '444444', 'stok']];

    /** Aman dijalankan ulang: produk hanya diisi bila kosong, akun yang sudah ada (dan PIN-nya) tidak disentuh. */
    public function run(): void
    {
        $branch = DB::table('branches')->orderBy('id')->value('id') ?? DB::table('branches')->insertGetId(['name' => 'Cabang 1']);
        if (! DB::table('products')->exists()) {
            foreach (self::PRODUCTS as [$code, $sku, $name, $category, $unit, $price, $cost, $stock]) {
                $id = DB::table('products')->insertGetId(compact('sku', 'name', 'category', 'unit', 'price', 'cost'));
                DB::table('barcodes')->insert(['code' => $code, 'product_id' => $id]);
                DB::table('branch_products')->insert(['branch_id' => $branch, 'product_id' => $id, 'stock' => $stock]);
            }
        }
        foreach (self::CASHIERS as [$name, $pin, $role]) { // name unik: akun yang belum ada saja yang ditambahkan
            DB::table('cashiers')->insertOrIgnore(['name' => $name, 'pin_hash' => Hash::make($pin), 'role' => $role,
                'branch_id' => $role === 'kasir' ? $branch : null]);
        }
    }
}
