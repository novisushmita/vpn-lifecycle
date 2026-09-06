<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusAkun;
use App\Http\Controllers\Controller;
use App\Models\AkunVpn;
use App\Models\AuditLog;
use App\Models\OperasiRouter;
use App\Models\Pengajuan;
use App\Models\Vps;
use App\Jobs\CekKoneksiRouterJob;
use Illuminate\Support\Facades\Cache;
use App\Services\Vpn\AlokasiIp;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Dashboard hanya membaca cache. Bila router mati, memanggilnya di sini
        // berarti menunggu timeout penuh sebelum halaman muncul, padahal justru
        // saat itulah dashboard paling dibutuhkan.
        $router = Cache::get(CekKoneksiRouterJob::KUNCI, [
            'tersambung' => null,
            'pesan'      => 'Status router belum diperiksa. Pastikan penjadwal berjalan.',
        ]);

        return response()->json([
            'router'    => $router,
            'ringkasan' => [
                'pengajuan_menunggu' => Pengajuan::whereIn('status', ['diajukan', 'ditinjau'])->count(),
                'akun_aktif'         => AkunVpn::where('status', StatusAkun::Aktif)->count(),
                'akun_dinonaktifkan' => AkunVpn::where('status', StatusAkun::Dinonaktifkan)->count(),
                'akun_gagal'         => AkunVpn::where('status', StatusAkun::GagalProvision)->count(),
                'vps_total'          => Vps::count(),
                'vps_down'           => Vps::where('status_terakhir', 'down')->count(),
                'ip_tersisa'         => AlokasiIp::dariConfig()->jumlahTersisa(),
            ],
            'operasi_terakhir' => OperasiRouter::latest('id')->limit(10)
                ->get(['id', 'jenis', 'status', 'durasi_ms', 'created_at']),
            'audit_terakhir' => AuditLog::with('user')->latest('id')->limit(10)->get()
                ->map(fn ($a) => [
                    'aksi'      => $a->aksi,
                    'deskripsi' => $a->deskripsi,
                    'oleh'      => $a->user?->name,
                    'waktu'     => $a->created_at?->toIso8601String(),
                ]),
        ]);
    }
}
