<?php

namespace Tests\Feature;

use App\Http\Controllers\KasirController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KasirTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->login(); // PDA & daftar produk kini perlu login (supaya tahu cabangnya); default Kasir 1 di Cabang 1
    }

    private function login(string $pin = '111111') // PIN awal di DatabaseSeeder: Kasir 1, Admin 123456, Owner 888888, Staf Stok 444444
    {
        $res = $this->postJson('/api/login', ['pin' => $pin]);
        if ($res->status() === 200) {
            $this->withToken($res->json('token'));
        }

        return $res;
    }

    private function stock(string $code): int
    {
        return $this->getJson("/api/products/barcode/$code")->json('stock');
    }

    private function pid(string $code): int
    {
        return $this->getJson("/api/products/barcode/$code")->json('id');
    }

    private function beforeAfter($res): array
    {
        return array_map(fn ($i) => [$i['before'], $i['after']], $res->json('items'));
    }

    public function test_pages_load(): void
    {
        $this->get('/')->assertOk();
        $this->get('/pda')->assertOk();
        $this->getJson('/api/tidak-ada')->assertStatus(404)->assertJsonStructure(['error']);
    }

    // ---------- barang masuk ----------
    public function test_ean13_check_digit(): void
    {
        $this->assertTrue(KasirController::ean13Ok('8991234500065'));
        $this->assertFalse(KasirController::ean13Ok('8991234500066'));
        $this->assertTrue(KasirController::ean13Ok('ABC-123')); // bukan EAN-13: tidak dicek
    }

    public function test_known_and_unknown_barcode(): void
    {
        $this->getJson('/api/products/barcode/8991234500089')->assertOk()
            ->assertJson(['name' => 'Air Mineral 600ml', 'stock' => 120, 'barcodes' => ['8991234500089']]);
        $this->getJson('/api/products/barcode/8991234500133')->assertStatus(404)->assertJson(['barcode' => '8991234500133']);
        $this->getJson('/api/products/barcode/8991234500134')->assertStatus(400); // digit cek salah
        $this->getJson('/api/products/barcode/a%2Fb')->assertStatus(400);
        $this->assertCount(12, $this->getJson('/api/products')->json('products'));
    }

    public function test_receive_adds_stock_and_merges_duplicates(): void
    {
        $res = $this->postJson('/api/receipts', ['items' => [
            ['barcode' => '8991234500089', 'qty' => 20], ['barcode' => '8991234500089', 'qty' => 4],
            ['barcode' => '8991234500126', 'qty' => 6]]])->assertStatus(201);
        $this->assertMatchesRegularExpression('/^BM-\d{8}-01$/', $res->json('number'));
        $this->assertSame([[120, 144], [25, 31]], $this->beforeAfter($res));
        $this->assertSame('8991234500089', $res->json('items.0.barcode'));
        $this->assertSame(144, $this->stock('8991234500089'));
        $res = $this->postJson('/api/receipts', ['items' => [['barcode' => '8991234500089', 'qty' => 1]]]);
        $this->assertStringEndsWith('-02', $res->json('number'));
    }

    public function test_receive_is_all_or_nothing(): void
    {
        $this->postJson('/api/receipts', ['items' => [
            ['barcode' => '8991234500089', 'qty' => 5], ['barcode' => '8991234500133', 'qty' => 1]]])
            ->assertStatus(400)->assertExactJson(['error' => 'Ada barcode yang belum terdaftar', 'missing' => ['8991234500133']]);
        $this->assertSame(120, $this->stock('8991234500089'));
    }

    public function test_rejects_bad_qty(): void
    {
        foreach ([0, -3, 1.5, '2', true, 10000, null] as $q) {
            $this->postJson('/api/receipts', ['items' => [['barcode' => '8991234500089', 'qty' => $q]]])->assertStatus(400);
        }
        $this->postJson('/api/receipts', ['items' => []])->assertStatus(400);
        $this->postJson('/api/receipts', ['items' => ['x']])->assertStatus(400);
        $this->assertSame(120, $this->stock('8991234500089'));
    }

    public function test_new_product_then_receive(): void
    {
        $body = ['barcode' => '8991234500133', 'name' => 'Teh Melati Botol 350ml', 'category' => 'Minuman',
            'unit' => 'botol', 'price' => 6000, 'cost' => 3500, 'stock' => 50]; // stok awal diabaikan: hanya lewat barang masuk
        $res = $this->postJson('/api/products', $body)->assertStatus(201)->assertJson(['stock' => 0])->assertJsonMissingPath('cost');
        $this->assertSame(sprintf('PRD-%04d', $res->json('id')), $res->json('sku'));
        $this->assertSame(0, (int) \DB::table('products')->where('id', $res->json('id'))->value('cost')); // modal dari non-owner diabaikan
        $this->postJson('/api/products', $body)->assertStatus(409); // barcode dobel
        $this->postJson('/api/receipts', ['items' => [['barcode' => '8991234500133', 'qty' => 24]]]);
        $this->assertSame(24, $this->stock('8991234500133'));
    }

    public function test_link_barcode_to_existing_product(): void
    {
        $this->postJson('/api/products/'.$this->pid('8991234500065').'/barcodes', ['barcode' => 'KOPI-DUS-12'])
            ->assertStatus(201)->assertJson(['name' => 'Kopi Susu Gula Aren']);
        $res = $this->postJson('/api/receipts', ['items' => [
            ['barcode' => 'KOPI-DUS-12', 'qty' => 12], ['barcode' => '8991234500065', 'qty' => 1]]]);
        $this->assertSame([[40, 52], [52, 53]], $this->beforeAfter($res));
        $this->assertSame(53, $this->stock('8991234500065'));
    }

    public function test_validation_on_new_product(): void
    {
        $this->postJson('/api/products', ['barcode' => '8991234500140', 'name' => '', 'category' => 'Snack', 'price' => 1])->assertStatus(400);
        $this->postJson('/api/products', ['barcode' => '8991234500140', 'name' => 'X', 'category' => 'Snack', 'price' => -1])->assertStatus(400);
        $this->postJson('/api/products', ['barcode' => '8991234500140', 'name' => 'X', 'category' => 'Snack', 'price' => '1'])->assertStatus(400);
    }

    // ---------- kasir ----------
    public function test_login_and_lockout(): void
    {
        $this->withoutToken();
        $this->login(pin: '000000')->assertStatus(401);
        for ($i = 0; $i < 4; $i++) {
            $this->login(pin: '000000');
        }
        $this->login()->assertStatus(429); // perangkat dikunci walau PIN benar
        $this->login('123456')->assertStatus(429);
        $this->travel(61)->seconds();
        $this->login()->assertOk()->assertJson(['user' => ['name' => 'Kasir 1', 'role' => 'kasir', 'branch' => 'Cabang 1']]);
        $this->login('123456')->assertOk()->assertJson(['user' => ['name' => 'Admin', 'role' => 'admin']]);
        $this->getJson('/api/report')->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/report')->assertStatus(401);
    }

    public function test_pin_must_be_unique(): void
    {
        $this->artisan('kasir:pin', ['nama' => 'Admin'])->expectsQuestion('PIN baru (6 angka)', '111111')->assertExitCode(1);
        \DB::table('cashiers')->where('name', 'Admin')->update(['pin_hash' => \Hash::make('111111')]); // data lama yang terlanjur sama
        $this->login()->assertStatus(409);
        $this->artisan('kasir:pin', ['nama' => 'Admin'])->expectsQuestion('PIN baru (6 angka)', '222222')->assertExitCode(0);
        $this->login('222222')->assertOk()->assertJson(['user' => ['name' => 'Admin']]);
    }

    public function test_cashier_endpoints_need_login(): void
    {
        $this->withoutToken();
        $this->getJson('/api/products')->assertStatus(401);
        $this->getJson('/api/products/barcode/8991234500089')->assertStatus(401);
        $this->postJson('/api/receipts', ['items' => [['barcode' => '8991234500089', 'qty' => 1]]])->assertStatus(401);
        $this->postJson('/api/sales', ['items' => [['product_id' => 1, 'qty' => 1]], 'method' => 'QRIS'])->assertStatus(401);
        $this->getJson('/api/report')->assertStatus(401)->assertJsonStructure(['error']);
        $this->deleteJson('/api/products/1')->assertStatus(401);
        $this->putJson('/api/products/1', [])->assertStatus(401);
    }

    public function test_cash_sale_reduces_stock_and_adds_tax(): void
    {
        $this->login();
        [$teh, $nasi] = [$this->pid('8991234500058'), $this->pid('8991234500010')];
        $items = [['product_id' => $teh, 'qty' => 2], ['product_id' => $nasi, 'qty' => 1], ['product_id' => $teh, 'qty' => 1]];
        $this->postJson('/api/sales', ['items' => $items, 'method' => 'Tunai', 'paid' => 1000])->assertStatus(400);
        $this->postJson('/api/sales', ['items' => $items, 'method' => 'Transfer'])->assertStatus(400);
        $res = $this->postJson('/api/sales', ['items' => $items, 'method' => 'Tunai', 'paid' => 50000])->assertStatus(201)
            ->assertJson(['subtotal' => 40000, 'tax' => 4400, 'total' => 44400, 'change' => 5600, 'cashier' => 'Kasir 1']);
        $this->assertMatchesRegularExpression('/^INV-\d{8}-0001$/', $res->json('number'));
        $this->assertSame([77, 23], [$this->stock('8991234500058'), $this->stock('8991234500010')]);
    }

    public function test_sale_rejects_insufficient_stock(): void
    {
        $this->login();
        $res = $this->postJson('/api/sales', ['items' => [['product_id' => $this->pid('8991234500119'), 'qty' => 4]], 'method' => 'QRIS'])
            ->assertStatus(409);
        $this->assertStringContainsString('Pisang Goreng (sisa 3)', $res->json('error'));
        $this->assertSame(3, $this->stock('8991234500119'));
    }

    public function test_report_totals(): void
    {
        $this->login();
        $this->postJson('/api/sales', ['items' => [['product_id' => $this->pid('8991234500089'), 'qty' => 5]], 'method' => 'QRIS']);
        $this->postJson('/api/sales', ['items' => [['product_id' => $this->pid('8991234500010'), 'qty' => 1]], 'method' => 'Tunai', 'paid' => 30000]);
        $r = $this->getJson('/api/report')->assertOk()->json();
        $this->assertSame([2, 22200 + 27750, 6], [$r['count'], $r['revenue'], $r['items']]);
        $this->assertArrayNotHasKey('profit', $r); // kasir tidak melihat laba kotor
        $this->assertSame('Air Mineral 600ml', $r['top'][0]['name']);
        $this->assertSame(['Tunai', 'QRIS'], array_column($r['methods'], 'method'));
        $this->assertSame([['day' => now()->format('Y-m-d'), 'amount' => 49950]], $r['daily']);
        $this->assertSame(['Kasir 1', 1], [$r['recent'][0]['cashier'], $r['recent'][0]['items']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', $r['recent'][0]['created_at']);
        $this->assertSame(['Jus Alpukat', 'Pisang Goreng', 'Roti Bakar Coklat'], array_column($r['low_stock'], 'name'));
        $this->getJson('/api/report?from=2026-13-01')->assertStatus(400);
        $this->getJson('/api/report?from=2026-01-02&to=2026-01-01')->assertStatus(400);
        $this->getJson('/api/report?from=2020-01-01&to=2020-01-31')->assertOk()->assertJson(['count' => 0, 'revenue' => 0]);
        $this->login('123456');
        $this->getJson('/api/report')->assertJson(['count' => 2, 'profit' => 5 * (4000 - 2200) + (25000 - 14000)]);
    }

    // ---------- peran ----------
    public function test_cost_only_for_owner_and_admin_is_read_only(): void
    {
        $code = '8991234500089'; // Air Mineral: modal 2200
        $this->getJson("/api/products/barcode/$code")->assertOk()->assertJsonMissingPath('cost'); // kasir / PDA
        $pid = $this->pid($code);
        $this->assertArrayNotHasKey('cost', $this->getJson('/api/products')->json('products.0'));
        $edit = ['sku' => 'MNM-001', 'name' => 'Air Mineral 600ml', 'category' => 'Minuman', 'unit' => 'botol', 'price' => 4500, 'cost' => 1];
        $this->putJson("/api/products/$pid", $edit)->assertStatus(403); // katalog & harga hanya owner
        $this->deleteJson("/api/products/$pid")->assertStatus(403);

        $this->login('888888')->assertJson(['user' => ['role' => 'owner', 'branch' => null]]);
        $this->getJson("/api/products/barcode/$code")->assertStatus(400); // owner harus memilih cabang
        $this->getJson("/api/products/barcode/$code?branch=1")->assertJson(['cost' => 2200, 'stock' => 120]);
        $this->putJson("/api/products/$pid?branch=1", ['cost' => 2500] + $edit)->assertJson(['cost' => 2500, 'price' => 4500]);
        $this->postJson('/api/sales', ['items' => [['product_id' => $pid, 'qty' => 1]], 'method' => 'QRIS'])->assertStatus(403);

        $this->login('123456');
        $this->getJson("/api/products/barcode/$code")->assertJsonMissingPath('cost');
        $this->putJson("/api/products/$pid", $edit)->assertStatus(403);
        $this->deleteJson("/api/products/$pid")->assertStatus(403);
        $this->postJson('/api/sales', ['items' => [['product_id' => $pid, 'qty' => 1]], 'method' => 'QRIS'])->assertStatus(403);
        $this->postJson('/api/daily-reports')->assertStatus(403);
    }

    public function test_daily_report_upload(): void
    {
        $this->withoutToken()->getJson('/api/daily-reports')->assertStatus(401);
        $this->login();
        $this->postJson('/api/sales', ['items' => [['product_id' => $this->pid('8991234500089'), 'qty' => 5]], 'method' => 'QRIS']);
        $this->postJson('/api/daily-reports')->assertStatus(201)->assertJson(['count' => 1, 'revenue' => 22200]);
        $this->postJson('/api/sales', ['items' => [['product_id' => $this->pid('8991234500010'), 'qty' => 1]], 'method' => 'Tunai', 'paid' => 30000]);
        $this->postJson('/api/daily-reports')->assertStatus(201); // kirim ulang menimpa tanggal yang sama
        $d = $this->getJson('/api/daily-reports')->assertOk()->assertJsonCount(1)->json(0);
        $this->assertSame([now()->format('Y-m-d'), 'Kasir 1', 2, 6, 49950, 27750],
            [$d['date'], $d['cashier'], $d['count'], $d['items'], $d['revenue'], $d['cash']]);
        $this->assertArrayNotHasKey('profit', $d);
        $this->login('123456');
        $this->getJson('/api/daily-reports')->assertJson([['profit' => 5 * (4000 - 2200) + (25000 - 14000)]]);
    }

    public function test_delete_product_without_stock_is_immediate(): void
    {
        $pid = $this->pid('8991234500072'); // Jus Alpukat, stok 0
        $this->login('888888');
        $this->getJson('/api/products/barcode/8991234500072?branch=1')->assertJson(['delete_when_empty' => false]);
        $this->deleteJson("/api/products/$pid")->assertOk()->assertJson(['pending' => false]);
        $this->getJson('/api/products/barcode/8991234500072?branch=1')->assertStatus(404);
    }

    public function test_edit_and_delete_product(): void
    {
        $pid = $this->pid('8991234500096');
        $this->login('888888');
        $this->putJson("/api/products/$pid?branch=1", ['sku' => 'SNK-009', 'name' => 'Keripik Singkong Pedas',
            'category' => 'Snack', 'unit' => 'pcs', 'price' => 11000, 'cost' => 6000])
            ->assertOk()->assertJson(['name' => 'Keripik Singkong Pedas', 'price' => 11000, 'stock' => 35]);
        // stok masih 35: hanya ditandai, tetap dijual
        $this->deleteJson("/api/products/$pid")->assertOk()->assertJson(['pending' => true, 'stock' => 35]);
        $this->getJson('/api/products/barcode/8991234500096?branch=1')->assertOk()->assertJson(['delete_when_empty' => true]);
        $this->postJson("/api/products/$pid/keep")->assertOk();
        $this->getJson('/api/products/barcode/8991234500096?branch=1')->assertJson(['delete_when_empty' => false]);
        $this->login('123456');
        $this->postJson("/api/products/$pid/keep")->assertStatus(403); // admin tidak boleh
        $this->login('888888');
        $this->deleteJson("/api/products/$pid")->assertJson(['pending' => true]);
        $this->login();
        $sell = fn ($q) => $this->postJson('/api/sales', ['items' => [['product_id' => $pid, 'qty' => $q]], 'method' => 'QRIS']);
        $sell(34)->assertStatus(201);
        $this->getJson('/api/products/barcode/8991234500096')->assertOk()->assertJson(['stock' => 1]);
        $sell(1)->assertStatus(201); // stok habis → terhapus otomatis
        $this->getJson('/api/products/barcode/8991234500096')->assertStatus(404);
        $this->assertNotContains($pid, array_column($this->getJson('/api/products')->json('products'), 'id'));
        // barcode bekas produk terhapus boleh dipakai produk baru
        $this->postJson('/api/products', ['barcode' => '8991234500096', 'name' => 'Keripik Baru', 'category' => 'Snack', 'price' => 9000])
            ->assertStatus(201);
    }

    // ---------- cabang ----------
    public function test_branches_have_own_stock_price_and_reports(): void
    {
        $this->artisan('kasir:cabang', ['nama' => 'Cabang 2'])->assertExitCode(0);
        $this->artisan('kasir:akun', ['nama' => 'Kasir 2', 'peran' => 'kasir'])->assertExitCode(1); // kasir wajib punya cabang
        $this->artisan('kasir:akun', ['nama' => 'Kasir 2', 'peran' => 'kasir', '--cabang' => 'Cabang 2'])
            ->expectsQuestion('PIN baru (6 angka)', '222222')->assertExitCode(0);
        $b2 = \DB::table('branches')->where('name', 'Cabang 2')->value('id');
        $air = $this->pid('8991234500089');

        $this->login('888888'); // owner: harga khusus Cabang 2
        $this->putJson("/api/products/$air/price?branch=$b2", ['price' => 5000])->assertOk()
            ->assertJson(['price' => 5000, 'base_price' => 4000, 'branch_price' => 5000, 'stock' => 0]);

        $this->login('222222')->assertJson(['user' => ['name' => 'Kasir 2', 'branch' => 'Cabang 2']]);
        $this->assertSame(0, $this->stock('8991234500089')); // stok cabang baru mulai 0
        $this->postJson('/api/sales', ['items' => [['product_id' => $air, 'qty' => 1]], 'method' => 'QRIS'])->assertStatus(409);
        $this->postJson('/api/receipts', ['items' => [['barcode' => '8991234500089', 'qty' => 10]]])->assertStatus(201);
        $this->postJson('/api/sales', ['items' => [['product_id' => $air, 'qty' => 2]], 'method' => 'QRIS'])->assertStatus(201)
            ->assertJson(['subtotal' => 10000, 'total' => 11100]);
        $this->assertSame(8, $this->stock('8991234500089'));
        $this->postJson('/api/daily-reports')->assertStatus(201);
        $this->assertSame(1, $this->getJson('/api/report')->json('count'));

        $this->login(); // Kasir 1: stok & harga cabangnya tidak berubah, laporannya terpisah
        $this->getJson('/api/products/barcode/8991234500089')->assertJson(['stock' => 120, 'price' => 4000]);
        $this->assertSame(0, $this->getJson('/api/report')->json('count'));
        $this->postJson('/api/daily-reports')->assertStatus(201);
        $this->assertCount(1, $this->getJson('/api/daily-reports')->json());

        $this->login('123456'); // admin: semua cabang atau satu cabang
        $this->assertSame(1, $this->getJson('/api/report')->json('count'));
        $this->assertSame(0, $this->getJson('/api/report?branch=1')->json('count'));
        $this->assertSame('Cabang 2', $this->getJson('/api/report')->json('recent.0.branch'));
        $this->assertEqualsCanonicalizing(['Cabang 1', 'Cabang 2'], array_column($this->getJson('/api/daily-reports')->json(), 'branch'));
        $this->getJson('/api/report?branch=99')->assertStatus(400);

        // hapus saat stok habis berlaku per cabang: hilang dari Cabang 2 dulu, tetap dijual di Cabang 1
        $this->login('888888');
        $this->deleteJson("/api/products/$air")->assertJson(['pending' => true, 'stock' => 128]);
        $this->login('222222');
        $this->postJson('/api/sales', ['items' => [['product_id' => $air, 'qty' => 8]], 'method' => 'QRIS'])->assertStatus(201);
        $this->assertNotContains($air, array_column($this->getJson('/api/products')->json('products'), 'id'));
        $this->login();
        $this->assertContains($air, array_column($this->getJson('/api/products')->json('products'), 'id'));
    }

    public function test_stock_staff_only_sees_low_and_out_stock(): void
    {
        $this->artisan('kasir:cabang', ['nama' => 'Cabang 2'])->assertExitCode(0);
        $this->artisan('kasir:akun', ['nama' => 'Gudang Pusat', 'peran' => 'stok'])
            ->expectsQuestion('PIN baru (6 angka)', '555555')->assertExitCode(0);
        $this->login('555555')->assertJson(['user' => ['role' => 'stok', 'branch_id' => null]]);

        $all = $this->getJson('/api/stock-alerts')->assertOk()->assertJson(['threshold' => 5])
            ->assertJsonMissingPath('items.0.price')->assertJsonMissingPath('items.0.cost')->json('items');
        // Cabang 1 (data contoh): Jus Alpukat 0, Pisang Goreng 3, Roti Bakar 5; Cabang 2 baru: semua produk 0
        $c1 = array_values(array_filter($all, fn ($i) => $i['branch'] === 'Cabang 1'));
        $this->assertSame([['Jus Alpukat', 0], ['Pisang Goreng', 3], ['Roti Bakar Coklat', 5]],
            array_map(fn ($i) => [$i['name'], $i['stock']], $c1));
        $this->assertCount(12, array_filter($all, fn ($i) => $i['branch'] === 'Cabang 2'));
        $this->assertCount(3, $this->getJson('/api/stock-alerts?branch=1')->json('items'));
        $this->getJson('/api/branches')->assertOk();

        // hanya melihat: tanpa produk/harga, laporan, penjualan, atau barang masuk
        foreach (['/api/products', '/api/report', '/api/daily-reports', '/api/products/barcode/8991234500089'] as $url) {
            $this->getJson($url)->assertStatus(403);
        }
        $this->postJson('/api/receipts?branch=1', ['items' => [['barcode' => '8991234500089', 'qty' => 1]]])->assertStatus(403);
        $this->postJson('/api/products', ['name' => 'X', 'category' => 'Snack', 'price' => 1000])->assertStatus(403);

        $this->login(); // kasir tidak memakai halaman ini
        $this->getJson('/api/stock-alerts')->assertStatus(403);
        $this->login('123456'); // admin boleh
        $this->getJson('/api/stock-alerts?branch=1')->assertOk();
    }
}
