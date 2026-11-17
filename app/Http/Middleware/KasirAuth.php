<?php

namespace App\Http\Middleware;

use App\Http\Controllers\KasirController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Login kasir: token Bearer dari POST /api/login, disimpan di cache selama 12 jam. */
class KasirAuth
{
    /** $roles kosong = semua peran boleh; contoh pemakaian: KasirAuth::class.':kasir,owner'. */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = Cache::get(KasirController::tokenKey((string) $request->bearerToken()));
        if (! isset($user['role']) || ! array_key_exists('branch_id', $user)) { // sesi lama dari sebelum ada peran/cabang
            KasirController::fail(401, 'Sesi habis, silakan masuk lagi');
        }
        if ($roles && ! in_array($user['role'], $roles, true)) {
            KasirController::fail(403, 'Akun ini tidak punya akses ke fitur tersebut');
        }
        $request->attributes->set('kasir', $user);

        return $next($request);
    }
}
