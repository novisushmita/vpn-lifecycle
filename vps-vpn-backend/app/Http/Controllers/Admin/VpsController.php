<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanVpsRequest;
use App\Http\Resources\VpsAdminResource;
use App\Models\Vps;
use App\Services\PencatatAudit;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use App\Jobs\HapusVpsJob;
use App\Jobs\PingVpsJob;
use App\Models\OperasiRouter;
use App\Models\VpsHealthCheck;
use App\Services\Vpn\PenghapusVps;
use App\Services\Vpn\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VpsController extends Controller
{
    public function index()
    {
        return VpsAdminResource::collection(
            Vps::with('pingTerakhir')->withCount('akunVpn')->orderBy('nama')->get()
        );
    }

    public function store(SimpanVpsRequest $request): JsonResponse
    {
        $vps = Vps::create($request->validated());

        PencatatAudit::catat('tambah_vps', "Menambah VPS {$vps->nama} ({$vps->alamat_ip}).", $vps, null, $vps->toArray());

        return response()->json(new VpsAdminResource($vps), 201);
    }

    public function show(Vps $vp)
    {
        return new VpsAdminResource($vp->loadCount('akunVpn'));
    }

    public function update(SimpanVpsRequest $request, Vps $vp): JsonResponse
    {
        $lama = $vp->toArray();
        $vp->update($request->validated());

        PencatatAudit::catat('ubah_vps', "Mengubah VPS {$vp->nama}.", $vp, $lama, $vp->toArray());

        return response()->json(new VpsAdminResource($vp));
    }

    /**
     * Ringkasan dampak penghapusan, dipanggil sebelum konfirmasi ditampilkan
     * supaya admin tahu persis berapa akun yang ikut terhapus.
     */
    public function dampak(Vps $vp): JsonResponse
    {
        return response()->json(
            (new PenghapusVps(new ProvisioningService(RouterOsClient::dariConfig())))->dampak($vp)
        );
    }

    /**
     * Penghapusan berantai (CLAUDE.md 4.4b): setiap akun VPN milik VPS ini
     * di-deprovision penuh dari router lebih dulu, baru VPS-nya dihapus.
     *
     * Dijalankan lewat antrean karena satu VPS bisa memiliki banyak akun dan
     * tiap akun memerlukan beberapa panggilan ke router.
     */
    public function destroy(Request $request, Vps $vp): JsonResponse
    {
        $layanan = new PenghapusVps(new ProvisioningService(RouterOsClient::dariConfig()));
        $dampak  = $layanan->dampak($vp);

        if ($dampak['operasi_berjalan'] > 0) {
            return response()->json([
                'message' => 'Masih ada operasi router yang berjalan untuk VPS ini. Tunggu sampai selesai lalu ulangi.',
            ], 409);
        }

        if ($vp->sedang_dihapus) {
            return response()->json([
                'message' => 'Penghapusan VPS ini sudah berjalan sebelumnya.',
            ], 409);
        }

        HapusVpsJob::dispatch($vp->id, $request->user()->id);

        PencatatAudit::catat(
            'hapus_vps_berantai',
            "Memulai penghapusan berantai VPS {$vp->nama}: {$dampak['jumlah_akun']} akun ikut di-deprovision, "
            . "{$dampak['pengajuan_menunggu']} pengajuan dibatalkan.",
            $vp,
            $dampak,
        );

        return response()->json([
            'message'              => 'Penghapusan sedang diproses. Akun VPN dibersihkan dari router lebih dulu.',
            'jumlah_akun'          => $dampak['jumlah_akun'],
            'pengajuan_dibatalkan' => $dampak['pengajuan_menunggu'],
        ], 202);
    }

    /**
     * Ping on-demand dari sisi router, lewat antrean.
     *
     * Tiga paket beserta timeout memakan sekitar tiga detik; menjalankannya di
     * dalam request HTTP membuat halaman menggantung dan, pada banyak VPS
     * sekaligus, menghabiskan worker PHP-FPM (CLAUDE.md 6.2).
     */
    public function ping(Request $request, Vps $vp): JsonResponse
    {
        $operasi = OperasiRouter::create([
            'jenis'       => 'ping',
            'vps_id'      => $vp->id,
            'status'      => 'antre',
            'payload'     => ['alamat_ip' => $vp->alamat_ip],
            'dipicu_oleh' => $request->user()->id,
        ]);

        PingVpsJob::dispatch($operasi->id, $vp->id);

        return response()->json([
            'message'    => 'Pemeriksaan diantrekan.',
            'operasi_id' => $operasi->id,
        ], 202);
    }
}
