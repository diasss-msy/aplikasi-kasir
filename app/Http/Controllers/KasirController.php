<?php

namespace App\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** API website kasir + Barang Masuk (PDA SEUIC). Semua galat dikirim sebagai {"error": "..."}. */
class KasirController extends Controller
{
    const TAX_PERCENT = 11; // PPN 11%, sesuai desain layar Transaksi

    const METHODS = ['Tunai', 'QRIS', 'Kartu', 'E-Wallet'];

    const CATEGORIES = ['Makanan', 'Minuman', 'Snack', 'Kebutuhan Rumah', 'Lainnya'];

    const MAX_MONEY = 100_000_000;

    const SESSION_HOURS = 12;

    const MAX_ID = 2_147_483_647;

    // ---------- pemeriksaan input ----------
    /** Batas stok menipis (sama untuk laporan dan karyawan stok). */
    const LOW_STOCK = 5;

    public static function fail(int $status, string $message, array $extra = []): never
    {
        abort(response()->json(['error' => $message] + $extra, $status));
    }

    /** True bila bukan EAN-13, atau EAN-13 dengan digit cek benar (mendeteksi salah baca scanner). */
    public static function ean13Ok(string $code): bool
    {
        if (! preg_match('/^\d{13}$/D', $code)) {
            return true;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $code[12];
    }

    public static function tokenKey(string $token): string
    {
        return 'kasir-token:'.hash('sha256', $token);
    }

    private function barcode(mixed $code): string
    {
        $code = is_string($code) ? trim($code) : '';
        if (! preg_match('/^[0-9A-Za-z\-]{4,32}$/D', $code)) {
            self::fail(400, 'Format barcode tidak valid');
        }
        if (! self::ean13Ok($code)) {
            self::fail(400, 'Digit cek EAN-13 salah, kemungkinan salah baca. Scan ulang.');
        }

        return $code;
    }

    /** Ketat: "2", 1.5, dan true ditolak. */
    private function int(mixed $value, string $name, int $lo, int $hi): int
    {
        if (! is_int($value) || $value < $lo || $value > $hi) {
            self::fail(400, "{$name} harus bilangan bulat {$lo}–{$hi}");
        }

        return $value;
    }

    private function text(mixed $value, string $name, int $max, bool $required = true): string
    {
        $value = is_string($value) || $value === null ? trim($value ?? '') : null;
        if ($value === null || ($required && $value === '') || mb_strlen($value) > $max) {
            self::fail(400, "{$name} wajib diisi (maks. {$max} karakter)");
        }

        return $value;
    }

    /** Daftar item [{<kunci>, qty}] → [kunci => jumlah]; kunci yang sama dijumlahkan. */
    private function items(Request $r, int $max, string $emptyMessage, callable $key): array
    {
        $items = $r->input('items');
        if (! is_array($items) || ! array_is_list($items) || ! $items || count($items) > $max) {
            self::fail(400, $emptyMessage);
        }
        $qty = [];
        foreach ($items as $it) {
            if (! is_array($it)) {
                self::fail(400, 'Format item tidak valid');
            }
            $k = $key($it);
            $qty[$k] = ($qty[$k] ?? 0) + $this->int($it['qty'] ?? null, 'Jumlah', 1, 9999);
        }

        return $qty;
    }

    /** Nomor BM-/INV- dihitung per hari, jadi hanya satu penulis sekaligus (lintas proses PHP). */
    private function write(callable $fn): mixed
    {
        return Cache::lock('kasir-tulis', 10)->block(5, fn () => DB::transaction($fn));
    }

    private function nextNumber(string $table, string $prefix, int $width, Carbon $now): string
    {
        $head = $prefix.'-'.$now->format('Ymd').'-';
        $n = DB::table($table)->where('number', 'like', $head.'%')->count() + 1;

        return $head.str_pad((string) $n, $width, '0', STR_PAD_LEFT);
    }


    // ---------- login & cabang ----------
    /** Data akun dari token (null bila belum login); rute PDA juga memakainya untuk tahu cabang & peran. */
    private function me(): ?array
    {
        return Cache::get(self::tokenKey((string) request()->bearerToken()));
    }

    private function role(): ?string
    {
        return $this->me()['role'] ?? null;
    }

    /**
     * Cabang yang sedang dipakai. Kasir selalu cabangnya sendiri; owner/admin memilih lewat parameter `branch`.
     * $required = false: owner/admin boleh tanpa cabang (= semua cabang), hasilnya null.
     */
    private function branchId(bool $required = true): ?int
    {
        $own = $this->me()['branch_id'] ?? null;
        if ($own) {
            return $own;
        }
        $b = request()->input('branch');
        if ($b === null || $b === '') {
            return $required ? self::fail(400, 'Pilih cabang dulu') : null;
        }
        $id = filter_var($b, FILTER_VALIDATE_INT);
        if ($id === false || ! DB::table('branches')->where('id', $id)->exists()) {
            self::fail(400, 'Cabang tidak ditemukan');
        }

        return $id;
    }

    public function branches()
    {
        return DB::table('branches')->orderBy('id')->get(['id', 'name']);
    }

    public function login(Request $r)
    {
        $pin = $r->input('pin');
        if (! is_string($pin) || ! preg_match('/^\d{6}$/D', $pin)) {
            self::fail(400, 'PIN harus 6 angka');
        }
        $key = 'kasir-pin:'.$r->ip(); // akun tidak dipilih, jadi kunci per perangkat: 5x salah dalam 1 menit
        if (RateLimiter::tooManyAttempts($key, 5)) {
            self::fail(429, 'Terlalu banyak PIN salah. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.');
        }
        // PIN disimpan sebagai hash, jadi dicocokkan satu per satu. shortcut: lambat bila akun ratusan, cukup untuk beberapa cabang.
        $match = DB::table('cashiers')->get()->filter(fn ($u) => Hash::check($pin, $u->pin_hash));
        if ($match->isEmpty()) {
            RateLimiter::hit($key, 60);
            self::fail(401, 'PIN salah');
        }
        if ($match->count() > 1) {
            self::fail(409, 'PIN ini dipakai lebih dari satu akun. Ganti salah satunya: php artisan kasir:pin');
        }
        $u = $match->first();
        if ($u->role === 'kasir' && ! $u->branch_id) {
            self::fail(403, 'Akun kasir ini belum punya cabang. Atur dengan: php artisan kasir:akun');
        }
        RateLimiter::clear($key);
        $token = Str::random(48);
        $user = ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'branch_id' => $u->branch_id,
            'branch' => $u->branch_id ? DB::table('branches')->where('id', $u->branch_id)->value('name') : null];
        Cache::put(self::tokenKey($token), $user, now()->addHours(self::SESSION_HOURS));

        return ['token' => $token, 'user' => $user];
    }

    public function logout(Request $r)
    {
        Cache::forget(self::tokenKey((string) $r->bearerToken()));

        return ['ok' => true];
    }

    // ---------- produk ----------
    /**
     * Produk dengan stok & harga cabang yang dipakai. Harga modal (dan harga standar) hanya untuk owner.
     * Daftar penuh menyembunyikan produk bertanda "dihapus saat stok habis" yang stoknya di cabang ini sudah 0.
     */
    private function productList(?int $id = null): array
    {
        $b = $this->branchId();
        $owner = $this->role() === 'owner';
        $codes = DB::table('barcodes')->when($id !== null, fn ($q) => $q->where('product_id', $id))->orderBy('code')->get()->groupBy('product_id');
        $cols = ['p.id', 'p.sku', 'p.name', 'p.category', 'p.unit', DB::raw('COALESCE(bp.price, p.price) AS price'),
            DB::raw('COALESCE(bp.stock, 0) AS stock'), 'p.delete_when_empty'];
        if ($owner) {
            array_push($cols, 'p.cost', 'p.price AS base_price', 'bp.price AS branch_price');
        }

        return DB::table('products as p')
            ->leftJoin('branch_products as bp', fn ($j) => $j->on('bp.product_id', '=', 'p.id')->where('bp.branch_id', '=', $b))
            ->where('p.active', 1)
            ->when($id !== null, fn ($q) => $q->where('p.id', $id),
                fn ($q) => $q->where(fn ($w) => $w->where('p.delete_when_empty', 0)->orWhere('bp.stock', '>', 0)))
            ->orderBy('p.name')->get($cols)
            ->map(fn ($p) => array_merge((array) $p, ['price' => (int) $p->price, 'stock' => (int) $p->stock,
                'delete_when_empty' => (bool) $p->delete_when_empty, 'barcodes' => ($codes[$p->id] ?? collect())->pluck('code')->all()]))
            ->all();
    }

    private function product(int $id): array
    {
        return $this->productList($id)[0] ?? self::fail(404, 'Produk tidak ditemukan');
    }

    private function mustExist(int $id): void
    {
        if (! DB::table('products')->where('id', $id)->where('active', 1)->exists()) {
            self::fail(404, 'Produk tidak ditemukan');
        }
    }

    /** Baris stok cabang dibuat saat pertama dibutuhkan (tidak ada baris = stok 0, harga standar). */
    private function branchRow(int $branch, int $pid): void
    {
        DB::table('branch_products')->insertOrIgnore(['branch_id' => $branch, 'product_id' => $pid, 'stock' => 0]);
    }

    private function fields(Request $r): array
    {
        return [
            'name' => $this->text($r->input('name'), 'Nama produk', 80),
            'category' => $this->text($r->input('category'), 'Kategori', 40),
            'unit' => $this->text($r->input('unit') ?: 'pcs', 'Satuan', 20),
            'price' => $this->int($r->input('price'), 'Harga jual', 0, self::MAX_MONEY),
            'cost' => $this->int($r->input('cost', 0), 'Harga modal', 0, self::MAX_MONEY),
        ];
    }

    private function barcodeFree(string $code): void
    {
        if (DB::table('barcodes')->where('code', $code)->exists()) {
            self::fail(409, 'Barcode sudah dipakai produk lain');
        }
    }

    public function products()
    {
        return ['products' => $this->productList(), 'categories' => self::CATEGORIES,
            'methods' => self::METHODS, 'tax_percent' => self::TAX_PERCENT];
    }

    public function byBarcode(string $code)
    {
        $code = $this->barcode($code);
        $id = DB::table('barcodes')->where('code', $code)->value('product_id');

        return $id ? $this->product($id) : self::fail(404, 'Barcode belum terdaftar', ['barcode' => $code]);
    }

    /** Produk baru masuk katalog semua cabang dengan stok 0; stok bertambah lewat Barang Masuk tiap cabang. */
    public function createProduct(Request $r)
    {
        $this->branchId(); // cabang dicek dulu supaya produk tidak terbuat lalu gagal ditampilkan
        $code = $r->input('barcode') === null ? null : $this->barcode($r->input('barcode')); // dari web kasir barcode boleh kosong
        $sku = $this->text($r->input('sku'), 'SKU', 20, false);
        $data = $this->fields($r);
        if ($this->role() !== 'owner') {
            $data['cost'] = 0; // harga modal hanya diisi owner
        }
        $id = $this->write(function () use ($code, $sku, $data) {
            if ($code) {
                $this->barcodeFree($code);
            }
            $id = DB::table('products')->insertGetId($data);
            DB::table('products')->where('id', $id)->update(['sku' => $sku !== '' ? $sku : sprintf('PRD-%04d', $id)]);
            if ($code) {
                DB::table('barcodes')->insert(['code' => $code, 'product_id' => $id]);
            }

            return $id;
        });

        return response()->json($this->product($id), 201);
    }

    /** Owner: ubah data katalog (berlaku di semua cabang). `price` di sini = harga standar. */
    public function updateProduct(Request $r, int $id)
    {
        $data = ['sku' => $this->text($r->input('sku'), 'SKU', 20)] + $this->fields($r);
        $this->write(function () use ($id, $data) {
            $this->mustExist($id);
            DB::table('products')->where('id', $id)->update($data);
        });

        return $this->product($id);
    }

    /** Owner: harga jual khusus satu cabang; null = kembali ke harga standar. */
    public function branchPrice(Request $r, int $id)
    {
        $b = $this->branchId();
        $price = $r->input('price');
        if ($price !== null) {
            $price = $this->int($price, 'Harga cabang', 0, self::MAX_MONEY);
        }
        $this->write(function () use ($b, $id, $price) {
            $this->mustExist($id);
            $this->branchRow($b, $id);
            DB::table('branch_products')->where('branch_id', $b)->where('product_id', $id)->update(['price' => $price]);
        });

        return $this->product($id);
    }

    /**
     * Owner: hapus produk dari katalog. Selama stok di cabang mana pun masih ada, produk hanya ditandai:
     * tetap dijual di cabang yang masih punya stok, hilang dari cabang yang stoknya 0, dan terhapus
     * otomatis begitu stok semua cabang habis (lihat retire()). Riwayat penjualan tetap utuh.
     */
    public function deleteProduct(int $id)
    {
        $this->write(function () use ($id) {
            $this->mustExist($id);
            DB::table('products')->where('id', $id)->update(['delete_when_empty' => 1]);
            $this->retire([$id]);
        });
        $pending = DB::table('products')->where('id', $id)->where('active', 1)->exists();

        return ['ok' => true, 'pending' => $pending, 'stock' => $pending ? (int) DB::table('branch_products')->where('product_id', $id)->sum('stock') : 0];
    }

    /** Owner: batalkan tanda "dihapus saat stok habis". */
    public function keepProduct(int $id)
    {
        $this->write(function () use ($id) {
            $this->mustExist($id);
            DB::table('products')->where('id', $id)->update(['delete_when_empty' => 0]);
        });

        return ['ok' => true];
    }

    /** Hapus produk bertanda delete_when_empty yang stoknya di semua cabang sudah habis. Dipanggil di dalam write(). */
    private function retire(array $ids): void
    {
        $gone = DB::table('products as p')->whereIn('p.id', $ids)->where('p.active', 1)->where('p.delete_when_empty', 1)
            ->whereNotExists(fn ($q) => $q->from('branch_products as bp')->whereColumn('bp.product_id', 'p.id')->where('bp.stock', '>', 0))
            ->pluck('p.id');
        if ($gone->isNotEmpty()) {
            DB::table('products')->whereIn('id', $gone)->update(['active' => 0]);
            DB::table('barcodes')->whereIn('product_id', $gone)->delete(); // barcode boleh dipakai produk lain
        }
    }

    public function linkBarcode(Request $r, int $id)
    {
        $this->branchId();
        $code = $this->barcode($r->input('barcode'));
        $this->write(function () use ($id, $code) {
            $this->mustExist($id);
            $this->barcodeFree($code);
            DB::table('barcodes')->insert(['code' => $code, 'product_id' => $id]);
        });

        return response()->json($this->product($id), 201);
    }

    // ---------- barang masuk (PDA) ----------
    public function receive(Request $r)
    {
        $b = $this->branchId();
        $qty = $this->items($r, 500, 'Daftar barang kosong atau terlalu banyak', fn ($it) => $this->barcode($it['barcode'] ?? null));
        $now = now();

        return response()->json($this->write(function () use ($b, $qty, $now) { // semua masuk atau tidak sama sekali
            $rows = $missing = [];
            foreach ($qty as $code => $n) {
                $code = (string) $code; // PHP mengubah kunci array berupa angka menjadi int
                $p = DB::table('products')->join('barcodes', 'barcodes.product_id', '=', 'products.id')
                    ->where('barcodes.code', $code)->where('products.active', 1)->first(['products.id', 'products.name']);
                if ($p) {
                    $rows[] = [$p, $code, $n];
                } else {
                    $missing[] = $code;
                }
            }
            if ($missing) {
                self::fail(400, 'Ada barcode yang belum terdaftar', ['missing' => $missing]);
            }
            $number = $this->nextNumber('receipts', 'BM', 2, $now);
            $rid = DB::table('receipts')->insertGetId(['number' => $number, 'created_at' => $now, 'branch_id' => $b]);
            $result = [];
            foreach ($rows as [$p, $code, $n]) { // dua barcode bisa menunjuk produk yang sama: baca stok terbaru tiap kali
                $this->branchRow($b, $p->id);
                $row = DB::table('branch_products')->where('branch_id', $b)->where('product_id', $p->id);
                $before = (int) (clone $row)->lockForUpdate()->value('stock');
                $row->increment('stock', $n);
                DB::table('receipt_items')->insert(['receipt_id' => $rid, 'product_id' => $p->id, 'qty' => $n,
                    'stock_before' => $before, 'stock_after' => $before + $n]);
                $result[] = ['name' => $p->name, 'barcode' => $code, 'qty' => $n, 'before' => $before, 'after' => $before + $n];
            }

            return ['number' => $number, 'created_at' => $now->format('Y-m-d\TH:i:s'), 'items' => $result];
        }), 201);
    }

    // ---------- penjualan (kasir, di cabangnya sendiri) ----------
    public function sell(Request $r)
    {
        $qty = $this->items($r, 200, 'Keranjang kosong atau terlalu banyak',
            fn ($it) => $this->int($it['product_id'] ?? null, 'Produk', 1, self::MAX_ID));
        $method = $r->input('method');
        if (! in_array($method, self::METHODS, true)) {
            self::fail(400, 'Metode pembayaran tidak dikenal');
        }
        $user = $r->attributes->get('kasir');
        $b = $this->branchId();
        $now = now();

        return response()->json($this->write(function () use ($r, $qty, $method, $user, $b, $now) {
            $lines = $short = [];
            foreach ($qty as $pid => $n) {
                $p = DB::table('products')->where('id', $pid)->where('active', 1)->first(['name', 'price', 'cost']);
                if (! $p) {
                    self::fail(400, 'Ada produk yang sudah dihapus. Muat ulang halaman.');
                }
                $this->branchRow($b, $pid);
                $bp = DB::table('branch_products')->where('branch_id', $b)->where('product_id', $pid)->lockForUpdate()->first(['stock', 'price']);
                if ($bp->stock < $n) {
                    $short[] = "{$p->name} (sisa {$bp->stock})";
                }
                $lines[] = ['product_id' => $pid, 'name' => $p->name, 'price' => (int) ($bp->price ?? $p->price), 'qty' => $n, 'cost' => (int) $p->cost];
            }
            if ($short) {
                self::fail(409, 'Stok tidak cukup: '.implode(', ', $short));
            }
            $subtotal = array_sum(array_map(fn ($l) => $l['price'] * $l['qty'], $lines));
            $tax = intdiv($subtotal * self::TAX_PERCENT + 50, 100); // dibulatkan ke rupiah terdekat
            $total = $subtotal + $tax;
            $paid = $total;
            if ($method === 'Tunai') {
                $paid = $this->int($r->input('paid'), 'Uang diterima', 0, 10 * self::MAX_MONEY);
                if ($paid < $total) {
                    self::fail(400, 'Uang diterima kurang dari total');
                }
            }
            $number = $this->nextNumber('sales', 'INV', 4, $now);
            $sid = DB::table('sales')->insertGetId(['number' => $number, 'created_at' => $now, 'cashier_id' => $user['id'], 'branch_id' => $b,
                'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total, 'method' => $method, 'paid' => $paid, 'change_due' => $paid - $total]);
            foreach ($lines as &$l) {
                DB::table('sale_items')->insert(['sale_id' => $sid] + $l); // harga modal disimpan saat terjual, untuk laba kotor
                unset($l['cost']); // struk tidak menampilkan harga modal
                DB::table('branch_products')->where('branch_id', $b)->where('product_id', $l['product_id'])->decrement('stock', $l['qty']);
            }
            unset($l);
            $this->retire(array_column($lines, 'product_id'));

            return ['number' => $number, 'created_at' => $now->format('Y-m-d\TH:i:s'), 'cashier' => $user['name'], 'items' => $lines,
                'subtotal' => $subtotal, 'tax' => $tax, 'tax_percent' => self::TAX_PERCENT, 'total' => $total,
                'method' => $method, 'paid' => $paid, 'change' => $paid - $total];
        }), 201);
    }

    // ---------- laporan ----------
    /** Kasir: cabangnya sendiri. Owner/admin: ?branch= satu cabang, tanpa parameter = semua cabang. */
    public function report(Request $r)
    {
        $out = $this->summary($r->query('from'), $r->query('to'), $this->branchId(false));
        if ($this->role() === 'kasir') {
            unset($out['profit']); // laba kotor membuka harga modal
        }

        return $out;
    }

    /** Kasir mengirim rekap penjualan cabangnya hari ini; kirim ulang menimpa rekap tanggal itu. */
    public function uploadDaily(Request $r)
    {
        $b = $this->branchId();
        $s = $this->summary(null, null, $b);
        $row = ['cashier_id' => $r->attributes->get('kasir')['id'], 'created_at' => now(),
            'count' => $s['count'], 'items' => $s['items'], 'revenue' => $s['revenue'], 'profit' => $s['profit'],
            'cash' => (int) (collect($s['methods'])->firstWhere('method', 'Tunai')->amount ?? 0)];
        $this->write(fn () => DB::table('daily_reports')->updateOrInsert(['date' => $s['from'], 'branch_id' => $b], $row));

        return response()->json(['date' => $s['from'], 'count' => $s['count'], 'revenue' => $s['revenue']], 201);
    }

    public function dailyReports()
    {
        $b = $this->branchId(false);
        $profit = $this->role() === 'kasir' ? [] : ['d.profit'];

        return DB::table('daily_reports as d')->join('cashiers as u', 'u.id', '=', 'd.cashier_id')
            ->join('branches as b', 'b.id', '=', 'd.branch_id')->when($b, fn ($q) => $q->where('d.branch_id', $b))
            ->orderByDesc('d.date')->orderBy('b.name')->limit(120)
            ->get(['d.date', DB::raw("REPLACE(d.created_at, ' ', 'T') AS created_at"), 'u.name as cashier', 'b.name as branch',
                'd.count', 'd.items', 'd.revenue', 'd.cash', ...$profit]);
    }

    /** Produk yang stoknya ≤ 5 (menipis) atau 0 (habis) per cabang; produk tanpa baris stok di suatu cabang = 0 di sana. */
    private function lowStock(?int $branch, int $limit): \Illuminate\Support\Collection
    {
        return DB::table('products as p')->crossJoin('branches as b')
            ->leftJoin('branch_products as bp', fn ($j) => $j->on('bp.product_id', '=', 'p.id')->on('bp.branch_id', '=', 'b.id'))
            ->where('p.active', 1)->when($branch, fn ($q) => $q->where('b.id', $branch))
            ->whereRaw('COALESCE(bp.stock, 0) <= '.self::LOW_STOCK)
            ->where(fn ($q) => $q->where('p.delete_when_empty', 0)->orWhere('bp.stock', '>', 0)) // produk yang sedang dihapus tidak perlu dikirim lagi
            ->orderByRaw('COALESCE(bp.stock, 0)')->orderBy('b.id')->orderBy('p.name')->limit($limit)
            ->get(['p.id', 'p.name', 'p.sku', 'p.category', 'p.unit', DB::raw('CAST(COALESCE(bp.stock, 0) AS SIGNED) AS stock'),
                'b.id AS branch_id', 'b.name AS branch']);
    }

    /** Karyawan stok (dan admin/owner): daftar barang menipis & habis, satu cabang (?branch=) atau semua. Tanpa harga. */
    public function stockAlerts()
    {
        return ['threshold' => self::LOW_STOCK, 'items' => $this->lowStock($this->branchId(false), 1000)];
    }

    /** Rekap penjualan satu rentang tanggal (default: hari ini), satu cabang atau semua (null). */
    private function summary(mixed $from, mixed $to, ?int $branch): array
    {
        $day = function (mixed $s, string $default) {
            $s = $s ?: $default;
            $d = is_string($s) ? DateTimeImmutable::createFromFormat('!Y-m-d', $s) : false;

            return $d && $d->format('Y-m-d') === $s ? $d : self::fail(400, 'Tanggal harus berformat YYYY-MM-DD');
        };
        $start = $day($from, now()->format('Y-m-d'));
        $end = $day($to, $start->format('Y-m-d'));
        if ($end < $start || $start->diff($end)->days > 366) {
            self::fail(400, 'Rentang tanggal tidak valid (maks. 1 tahun)');
        }
        $rng = [$start->format('Y-m-d 00:00:00'), $end->modify('+1 day')->format('Y-m-d 00:00:00')];
        $w = 's.created_at >= ? AND s.created_at < ?';
        if ($branch) {
            $w .= ' AND s.branch_id = ?';
            $rng[] = $branch;
        }
        $sum = fn (string $expr) => "CAST(COALESCE(SUM($expr), 0) AS SIGNED)"; // SUM di MySQL berupa DECIMAL (string di PHP)

        $out = (array) DB::selectOne("SELECT COUNT(*) AS count, {$sum('total')} AS revenue, {$sum('tax')} AS tax FROM sales s WHERE $w", $rng)
            + (array) DB::selectOne("SELECT {$sum('si.qty')} AS items, {$sum('si.qty * (CAST(si.price AS SIGNED) - CAST(si.cost AS SIGNED))')} AS profit
                FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE $w", $rng);
        $out['methods'] = DB::select("SELECT method, COUNT(*) AS count, {$sum('total')} AS amount FROM sales s WHERE $w
            GROUP BY method ORDER BY amount DESC", $rng);
        $out['top'] = DB::select("SELECT si.name, {$sum('si.qty')} AS qty, {$sum('si.qty * si.price')} AS amount FROM sale_items si
            JOIN sales s ON s.id = si.sale_id WHERE $w GROUP BY si.product_id, si.name ORDER BY qty DESC LIMIT 5", $rng);
        $out['daily'] = DB::select("SELECT DATE(s.created_at) AS day, {$sum('total')} AS amount FROM sales s WHERE $w
            GROUP BY day ORDER BY day", $rng);
        $out['recent'] = DB::select("SELECT s.number, REPLACE(s.created_at, ' ', 'T') AS created_at, s.method, s.total, u.name AS cashier,
              b.name AS branch, (SELECT {$sum('qty')} FROM sale_items WHERE sale_id = s.id) AS items
            FROM sales s JOIN cashiers u ON u.id = s.cashier_id JOIN branches b ON b.id = s.branch_id WHERE $w ORDER BY s.id DESC LIMIT 20", $rng);
        $out['low_stock'] = $this->lowStock($branch, 50);

        return $out + ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'branch' => $branch];
    }
}
