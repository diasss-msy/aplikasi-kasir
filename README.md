# Aplikasi Kasir (Laravel) — kasir + Barang Masuk (PDA SEUIC), database MySQL

Satu server untuk semua perangkat di toko:

| Halaman | Alamat | Untuk |
|---|---|---|
| **Kasir** | `http://<IP>:8082` | Laptop/tablet kasir: login PIN, transaksi, pembayaran + struk, manajemen produk, laporan |
| **Barang Masuk** | `http://<IP>:8082/pda` | PDA SEUIC: scan barcode barang datang, stok langsung bertambah |

Data tersimpan di **MySQL/MariaDB XAMPP**, database `kasir_laravel` (bisa dilihat di phpMyAdmin).

## 1. Jalankan
Butuh **PHP 8.3+** (Laravel 13). PHP bawaan XAMPP (8.2) terlalu lama, jadi aplikasi ini **tidak bisa dibuka lewat
Apache XAMPP** (`localhost/laravel/claude/kasir`). Jalankan dengan PHP Homebrew lewat `php artisan serve`:

1. Buka **XAMPP**, nyalakan **MySQL** (port 3306).
2. Isi sandi user `root` MySQL di file `.env`, baris `DB_PASSWORD=` (pakai tanda kutip bila ada karakter khusus).
3. Sekali saja, buat database, tabel, 12 produk contoh, dan 2 akun kasir:
   ```bash
   php artisan migrate --seed --force
   ```
4. Jalankan server (biarkan terminalnya terbuka):
   ```bash
   php artisan serve --host 0.0.0.0 --port 8082
   ```
   Kasir buka `http://<IP-laptop>:8082`, PDA buka `http://<IP-laptop>:8082/pda` (harus satu Wi-Fi).

### Akun awal
| Akun | PIN | Peran | Yang bisa dilakukan |
|---|---|---|---|
| Kasir 1 | `111111` | kasir (Cabang 1) | Transaksi, tambah produk baru (tanpa harga modal), Barang Masuk, laporan cabangnya tanpa laba kotor, kirim laporan harian cabangnya |
| Admin | `123456` | admin (semua cabang) | Hanya membaca laporan semua cabang atau satu cabang (termasuk laba kotor) dan laporan harian |
| Owner | `888888` | owner (semua cabang) | Kelola katalog produk (ubah, hapus, harga modal, harga standar, harga per cabang), laporan semua cabang. Tidak bisa melakukan transaksi penjualan |
| Staf Stok | `444444` | stok (semua cabang) | Hanya melihat barang yang menipis (stok ≤ 5) dan habis per cabang, untuk dikirimi stok baru. Tanpa harga, laporan, penjualan, atau Barang Masuk |

### Cabang
Katalog produk satu untuk semua cabang (dikelola owner), tetapi **stok dan harga jual per cabang**. Cabang baru mulai dengan stok 0 untuk semua produk; stok bertambah lewat Barang Masuk (PDA) di cabang itu. Harga cabang yang dikosongkan memakai harga standar. Kasir terikat ke satu cabang; admin dan owner memilih cabang di layar Produk dan Laporan, dan di PDA memilih cabang tujuan stok.
```bash
php artisan kasir:cabang "Cabang Sudirman"
```
```bash
php artisan kasir:cabang "Cabang Pusat" --ganti="Cabang 1"
```
```bash
php artisan kasir:akun "Kasir 2" kasir --cabang="Cabang Sudirman"
```
```bash
php artisan kasir:akun "Gudang 1" stok
```
`kasir:akun` meminta PIN baru (harus beda dengan akun lain). Untuk admin/owner tidak perlu `--cabang`.

**Ganti PIN ini sebelum dipakai sungguhan** (ulangi untuk tiap akun):
```bash
php artisan kasir:pin "Owner"
```

**Memperbarui pemasangan lama** (menambah peran, akun Owner, laporan harian, dan cabang; semua data lama dipindah ke "Cabang 1", PIN yang sudah diganti tidak disentuh). Cadangkan database dulu. Setelah itu semua perangkat, termasuk PDA, perlu masuk ulang:
```bash
php artisan migrate --force
```
```bash
php artisan db:seed --force
```
Layar masuk hanya meminta PIN; akun dikenali dari PIN-nya, jadi **PIN tiap akun harus berbeda** (`kasir:pin` menolak PIN yang sudah dipakai akun lain). Salah PIN 5 kali dalam 1 menit mengunci perangkat itu sampai menit tersebut habis.

## 2. Atur scanner SEUIC (sekali saja)
Buka aplikasi **Scan Tool / Pengaturan Scanner** bawaan PDA SEUIC:

| Pengaturan | Nilai |
|---|---|
| Mode output (Output mode) | **Keyboard / Simulate keystroke** (bukan *Broadcast* atau *Clipboard*) |
| Akhiran (Suffix / End char) | **Enter** |
| Simbologi | Aktifkan **EAN-13**, **EAN-8**, **Code 128** (sesuai label barang) |

Scanner USB di meja kasir juga bisa dipakai: di layar Transaksi, scan barcode ke kolom pencarian dan barangnya langsung masuk ke pesanan.

## Cara pakai
**Kasir**: masukkan PIN. Pilih produk atau scan barcode, lalu tekan **Bayar** dan pilih metode (Tunai, QRIS, Kartu, E-Wallet). Untuk Tunai, kembalian dihitung otomatis. Struk bisa dicetak. PPN 11% ditambahkan ke subtotal. Penjualan ditolak bila stok tidak cukup.

**Produk**: kasir dan PDA bisa menambah produk baru; mengubah, menghapus, dan mengatur harga hanya owner (karena berlaku untuk semua cabang). Harga modal hanya terlihat dan bisa diisi oleh owner. Di formulir ubah produk, owner mengisi harga jual standar dan harga khusus cabang yang sedang dipilih. Produk baru selalu mulai dari stok 0; stok bertambah lewat Barang Masuk (PDA). Produk yang stoknya masih ada di cabang mana pun tidak langsung hilang saat dihapus: produk diberi label **Dihapus saat stok habis**, tetap dijual di cabang yang masih punya stok, hilang dari cabang yang stoknya sudah 0, lalu terhapus otomatis begitu stok semua cabang habis terjual. Tombol **Batal hapus** di baris produk itu membatalkannya. Produk dengan stok 0 langsung terhapus. Produk yang terhapus hilang dari kasir, tapi riwayat penjualannya tetap ada di laporan, dan barcode-nya bisa dipakai lagi.

**Laporan**: total penjualan, jumlah transaksi, rata-rata, laba kotor (harga jual − modal; tidak tampil untuk kasir), grafik per hari, metode pembayaran, produk terlaris, stok menipis (≤ 5), dan 20 transaksi terakhir. Pilih Hari ini / 7 hari / 30 hari, atau tanggal sendiri. Kasir menekan **Kirim Laporan Hari Ini** saat tutup toko: ringkasan penjualan cabangnya hari itu tersimpan di server dan muncul di daftar **Laporan harian terkirim** untuk admin dan owner (lengkap dengan laba kotor dan nama cabang). Satu laporan per tanggal per cabang; kirim ulang menimpa yang lama. Admin/owner bisa memilih **Semua cabang** atau satu cabang.

**Stok (karyawan stok)**: daftar barang habis dan menipis (≤ 5), dikelompokkan per cabang, bisa disaring Semua/Habis/Menipis dan per cabang. Datanya dari stok cabang saat ini; tekan **Muat ulang** untuk memperbarui.

**CCTV (demo)**: menu khusus owner dan admin. Pilih cabang, lalu tampil 4 kamera (Kasir, Pintu masuk, Rak barang, Gudang) dengan jam berjalan; ketuk kamera untuk memperbesar (layar penuh di HP, ketuk lagi untuk kembali). Gambarnya masih tiruan dan tidak ada data kamera di server.

**Barang Masuk (PDA)**: masuk dengan PIN (kasir otomatis ke cabangnya; admin/owner memilih cabang tujuan stok), lalu scan barang yang datang, atur jumlah, lalu **Simpan ke Stok**. Semua barang disimpan sekaligus (berhasil semua atau tidak sama sekali). Barcode yang belum terdaftar bisa langsung dibuat sebagai produk baru atau dihubungkan ke produk yang sudah ada. Barcode EAN-13 dengan digit cek salah otomatis ditolak.

**Animasi & anti-jenuh**: semua layar punya animasi singkat (masuk layar, tombol, keranjang, total, struk) yang tidak menahan ketukan atau scan. Sapaan dan warna latar mengikuti jam (pagi/siang/sore/malam). Kasir melihat hitungan "N transaksi hari ini" (confetti tiap transaksi ke-10) dan pengingat istirahat tiap 2 jam saat keranjang kosong. Animasi mati sendiri bila perangkat disetel "kurangi gerakan" (reduce motion).

## Isi kode
| File | Isi |
|---|---|
| `app/Http/Controllers/KasirController.php` | Seluruh API (`/api/...`) |
| `app/Http/Middleware/KasirAuth.php` | Cek login (token) dan peran untuk penjualan, laporan, ubah/hapus produk |
| `routes/api.php`, `routes/web.php` | Daftar alamat |
| `database/migrations/` | Tabel (termasuk peran akun dan `daily_reports`) |
| `database/seeders/DatabaseSeeder.php` | Produk contoh + akun awal |
| `public/kasir.html`, `public/pda.html` | Tampilan |
| `tests/Feature/KasirTest.php` | Uji |

## Uji
Tidak butuh MySQL (memakai SQLite di memori):
```bash
php artisan test
```

## Batasan saat ini
- Belum ada layar kelola akun: peran ada di kolom `cashiers.role` (`kasir`/`admin`/`owner`), ganti PIN lewat `php artisan kasir:pin`.
- Produk yang dibuat kasir atau lewat PDA tersimpan dengan harga modal 0 sampai owner mengisinya di layar Produk; selama itu laba kotornya terhitung terlalu besar.
- Server masih berjalan di satu laptop (`php artisan serve`). Supaya semua cabang memakai data yang sama, aplikasi perlu dipasang di server pusat yang bisa diakses lewat internet (belum dilakukan).
- Belum ada pemindahan stok antar cabang.
- Hitungan "transaksi hari ini" di layar kasir disimpan di perangkat masing-masing (hanya penyemangat); angka resminya tetap di Laporan.
- CCTV masih demo: daftar kamera tertulis di `public/kasir.html` (`CAMS`). Untuk kamera sungguhan perlu NVR/kamera di tiap cabang yang bisa diakses dari server pusat, lalu tabel kamera per cabang dan pemutar stream (RTSP diubah ke HLS/WebRTC).
- Barang Masuk untuk produk bertanda ini tetap diterima dan menunda penghapusannya.
- Belum ada: kode diskon, tahan pesanan, retur/refund, koreksi stok manual, dan batalkan penerimaan barang.
- `php artisan serve` adalah server pengembangan: cukup untuk satu toko kecil, tetapi berhenti saat terminal ditutup atau laptop mati.
- Paket Docker untuk NAS UGREEN (dari versi Python) belum dibuat ulang untuk versi Laravel ini.
