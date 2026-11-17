<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/** Minta PIN baru; login hanya memakai PIN, jadi PIN tiap akun harus beda. Mengembalikan hash, atau null bila ditolak. */
$askPin = function ($cmd, string $nama): ?string {
    $pin = $cmd->secret('PIN baru (6 angka)');
    if (! preg_match('/^\d{6}$/D', (string) $pin)) {
        $cmd->error('PIN harus 6 angka');

        return null;
    }
    if (DB::table('cashiers')->where('name', '!=', $nama)->get()->contains(fn ($u) => Hash::check($pin, $u->pin_hash))) {
        $cmd->error('PIN ini sudah dipakai akun lain, pilih PIN lain');

        return null;
    }

    return Hash::make($pin);
};

// Ganti PIN:  php artisan kasir:pin "Admin"
Artisan::command('kasir:pin {nama}', function (string $nama) use ($askPin) {
    if (! DB::table('cashiers')->where('name', $nama)->exists()) {
        $this->error("Akun \"$nama\" tidak ditemukan.");

        return 1;
    }
    if (! $hash = $askPin($this, $nama)) {
        return 1;
    }
    DB::table('cashiers')->where('name', $nama)->update(['pin_hash' => $hash]);
    $this->info("PIN $nama diganti.");

    return 0;
})->purpose('Ganti PIN seorang kasir');

// Tambah cabang:  php artisan kasir:cabang "Cabang Sudirman"   ·   ganti nama: --ganti="Cabang 1"
Artisan::command('kasir:cabang {nama} {--ganti= : nama cabang lama yang diganti namanya}', function (string $nama) {
    $nama = trim($nama);
    if ($nama === '' || mb_strlen($nama) > 40 || DB::table('branches')->where('name', $nama)->exists()) {
        $this->error('Nama cabang kosong, terlalu panjang, atau sudah dipakai.');

        return 1;
    }
    if ($lama = $this->option('ganti')) {
        $n = DB::table('branches')->where('name', $lama)->update(['name' => $nama]);
        $n ? $this->info("Cabang \"$lama\" sekarang bernama \"$nama\".") : $this->error("Cabang \"$lama\" tidak ditemukan.");

        return $n ? 0 : 1;
    }
    DB::table('branches')->insert(['name' => $nama]);
    $this->info("Cabang \"$nama\" ditambahkan. Semua produk ada di sana dengan stok 0 dan harga standar.");

    return 0;
})->purpose('Tambah atau ganti nama cabang');

// Tambah/ubah akun:  php artisan kasir:akun "Kasir 2" kasir --cabang="Cabang Sudirman"   (admin/owner tanpa --cabang)
Artisan::command('kasir:akun {nama} {peran : kasir, admin, owner, atau stok} {--cabang= : wajib untuk kasir}', function (string $nama, string $peran) use ($askPin) {
    $nama = trim($nama);
    if ($nama === '' || mb_strlen($nama) > 40 || ! in_array($peran, ['kasir', 'admin', 'owner', 'stok'], true)) {
        $this->error('Nama wajib (maks. 40 karakter) dan peran harus kasir, admin, owner, atau stok.');

        return 1;
    }
    $branch = null;
    if ($peran === 'kasir') {
        $branch = DB::table('branches')->where('name', (string) $this->option('cabang'))->value('id');
        if (! $branch) {
            $this->error('Kasir wajib punya cabang yang ada: --cabang="'.DB::table('branches')->orderBy('id')->value('name').'"');

            return 1;
        }
    }
    $exists = DB::table('cashiers')->where('name', $nama)->exists();
    $row = ['role' => $peran, 'branch_id' => $branch];
    if (! $exists || $this->confirm("Akun \"$nama\" sudah ada. Ganti PIN-nya juga?", false)) {
        if (! $row['pin_hash'] = $askPin($this, $nama)) {
            return 1;
        }
    }
    DB::table('cashiers')->updateOrInsert(['name' => $nama], $row);
    $this->info(($exists ? 'Akun diperbarui: ' : 'Akun dibuat: ')."$nama · $peran".($branch ? ' · '.$this->option('cabang') : ' · semua cabang'));

    return 0;
})->purpose('Tambah atau ubah akun kasir/admin/owner/stok');
