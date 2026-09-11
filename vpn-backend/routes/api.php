<?php

use App\Http\Controllers\Admin\AkunVpnController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DriftController;
use App\Http\Controllers\Admin\PaketBandwidthController;
use App\Http\Controllers\Admin\PengaturanController;
use App\Http\Controllers\Admin\PengajuanController as AdminPengajuanController;
use App\Http\Controllers\Admin\VpsController as AdminVpsController;
use App\Http\Controllers\Publik\PengajuanController;
use App\Http\Controllers\Publik\PerpanjanganController;
use App\Http\Controllers\Publik\VpsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Endpoint PUBLIK — tanpa autentikasi
|--------------------------------------------------------------------------
| Hanya menampilkan nama dan keterangan VPS. Alamat IP tidak pernah keluar
| dari sini, dan status pengajuan tidak pernah memuat kredensial.
*/
Route::get('vps', [VpsController::class, 'index']);
Route::post('pengajuan', [PengajuanController::class, 'store']);
Route::get('pengajuan/{nomor}', [PengajuanController::class, 'show']);
Route::post('perpanjangan', [PerpanjanganController::class, 'store']);

/*
|--------------------------------------------------------------------------
| Autentikasi admin
|--------------------------------------------------------------------------
*/
Route::post('admin/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

/*
|--------------------------------------------------------------------------
| Dashboard admin — wajib token Sanctum
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::get('dashboard', DashboardController::class);

    // Pengajuan: ACC/REJECT hanya lewat detail, sesuai alur yang disepakati.
    Route::get('pengajuan', [AdminPengajuanController::class, 'index']);
    Route::get('pengajuan/{pengajuan}', [AdminPengajuanController::class, 'show']);
    Route::post('pengajuan/{pengajuan}/setujui', [AdminPengajuanController::class, 'setujui']);
    Route::post('pengajuan/{pengajuan}/tolak', [AdminPengajuanController::class, 'tolak']);

    // VPS
    Route::apiResource('vps', AdminVpsController::class)->parameters(['vps' => 'vp']);
    Route::get('vps/{vp}/dampak-hapus', [AdminVpsController::class, 'dampak']);
    Route::post('vps/{vp}/ping', [AdminVpsController::class, 'ping']);

    // Akun VPN — transisi siklus hidup
    Route::get('akun', [AkunVpnController::class, 'index']);
    Route::get('akun/{akun}', [AkunVpnController::class, 'show']);
    Route::put('akun/{akun}', [AkunVpnController::class, 'update']);
    Route::get('akun/{akun}/kredensial', [AkunVpnController::class, 'kredensial']);
    Route::get('akun/{akun}/operasi', [AkunVpnController::class, 'operasi']);
    Route::get('akun/{akun}/sesi', [AkunVpnController::class, 'sesi']);
    Route::post('akun/{akun}/nonaktifkan', [AkunVpnController::class, 'nonaktifkan']);
    Route::post('akun/{akun}/aktifkan', [AkunVpnController::class, 'aktifkan']);
    Route::post('akun/{akun}/ulangi-provision', [AkunVpnController::class, 'ulangiProvision']);
    Route::delete('akun/{akun}', [AkunVpnController::class, 'destroy']);

    // Deteksi & rekonsiliasi drift
    Route::get('drift', [DriftController::class, 'index']);
    Route::post('drift/periksa', [DriftController::class, 'periksa']);
    Route::get('drift/status', [DriftController::class, 'status']);

    // Buku besar operasi router. ?jenis= menyaring satu jenis (mis. sinkron
    // untuk grafik), tanpa filter = seluruh jenis untuk tabel.
    Route::get('operasi', function (Illuminate\Http\Request $req) {
        $q = App\Models\OperasiRouter::query()
            ->with(['akunVpn:id,username', 'vps:id,nama', 'dipicuOleh:id,name'])
            ->latest('id');

        if ($jenis = $req->query('jenis')) {
            $q->where('jenis', $jenis);
        }

        return $q->limit((int) $req->query('limit', 200))->get()->map(fn ($o) => [
            'id'           => $o->id,
            'jenis'        => $o->jenis,
            'status'       => $o->status,
            'akun'         => $o->akunVpn?->username,
            'vps'          => $o->vps?->nama,
            'durasi_ms'    => $o->durasi_ms,
            'percobaan'    => $o->percobaan,
            'pesan_error'  => $o->pesan_error,
            'dipicu_oleh'  => $o->dipicuOleh?->name,
            'dimulai_pada' => $o->dimulai_pada?->toIso8601String(),
            'selesai_pada' => $o->selesai_pada?->toIso8601String(),
        ]);
    });

    // Dipantau antarmuka setelah menerima balasan 202 dari operasi antrean.
    Route::get('operasi/{operasi}', function (App\Models\OperasiRouter $operasi) {
        return response()->json([
            'id'           => $operasi->id,
            'jenis'        => $operasi->jenis,
            'status'       => $operasi->status,
            'pesan_error'  => $operasi->pesan_error,
            'hasil'        => $operasi->hasil,
            'durasi_ms'    => $operasi->durasi_ms,
            'selesai_pada' => $operasi->selesai_pada?->toIso8601String(),
        ]);
    });
    Route::post('drift/{drift}/selesaikan', [DriftController::class, 'selesaikan']);

    // Pengaturan umum
    Route::get('pengaturan', [PengaturanController::class, 'index']);
    Route::put('pengaturan', [PengaturanController::class, 'update']);

    // Paket bandwidth: perubahan merambat ke PPP profile di router
    Route::get('paket', [PaketBandwidthController::class, 'index']);
    Route::post('paket', [PaketBandwidthController::class, 'store']);
    Route::put('paket/{paket}', [PaketBandwidthController::class, 'update']);
    Route::delete('paket/{paket}', [PaketBandwidthController::class, 'destroy']);
    Route::post('paket/{paket}/selaraskan', [PaketBandwidthController::class, 'selaraskan']);

    Route::get('paket-bandwidth', fn () => App\Models\PaketBandwidth::where('aktif', true)->get());
});
