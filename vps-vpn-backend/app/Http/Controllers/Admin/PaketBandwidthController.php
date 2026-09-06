<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanPaketRequest;
use App\Jobs\HapusProfilPppJob;
use App\Jobs\SelaraskanPaketJob;
use App\Models\AkunVpn;
use App\Models\PaketBandwidth;
use App\Services\PencatatAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaketBandwidthController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            PaketBandwidth::withCount('akunVpn')->orderBy('id')->get()->map(fn ($p) => [
                'id'          => $p->id,
                'nama'        => $p->nama,
                'rx_rate'     => $p->rx_rate,
                'tx_rate'     => $p->tx_rate,
                'rate_limit'  => $p->rateLimit(),
                'ppp_profile' => $p->ppp_profile,
                'keterangan'  => $p->keterangan,
                'aktif'       => $p->aktif,
                'jumlah_akun' => $p->akun_vpn_count,
            ])
        );
    }

    public function store(SimpanPaketRequest $request): JsonResponse
    {
        $paket = PaketBandwidth::create($request->validated());

        // Baris basis data saja tidak cukup: PPP profile-nya harus benar-benar
        // ada di router sebelum ada akun yang memakainya.
        SelaraskanPaketJob::dispatch($paket->id, $request->user()->id);

        PencatatAudit::catat('tambah_paket', "Menambah paket {$paket->nama} ({$paket->rateLimit()}).", $paket, null, $paket->toArray());

        return response()->json([
            'message' => 'Paket ditambahkan. Profile di router sedang disiapkan.',
            'id'      => $paket->id,
        ], 201);
    }

    public function update(SimpanPaketRequest $request, PaketBandwidth $paket): JsonResponse
    {
        $lama = $paket->toArray();
        $paket->update($request->validated());

        SelaraskanPaketJob::dispatch($paket->id, $request->user()->id);

        PencatatAudit::catat('ubah_paket', "Mengubah paket {$paket->nama}.", $paket, $lama, $paket->toArray());

        return response()->json([
            'message' => 'Paket diperbarui. Perubahan pada router sedang diterapkan.',
        ]);
    }

    /**
     * Paket yang masih dipakai akun tidak boleh dihapus: akun-akun itu akan
     * kehilangan acuan rate-limit dan tidak dapat diprovision ulang.
     */
    public function destroy(Request $request, PaketBandwidth $paket): JsonResponse
    {
        $dipakai = AkunVpn::withTrashed()->where('paket_bandwidth_id', $paket->id)->count();

        if ($dipakai > 0) {
            return response()->json([
                'message'     => "Paket masih dipakai {$dipakai} akun VPN, termasuk yang sudah dihapus. Nonaktifkan saja agar tidak dapat dipilih pada pengajuan baru.",
                'jumlah_akun' => $dipakai,
            ], 422);
        }

        $nama    = $paket->nama;
        $profile = $paket->ppp_profile;
        $paket->delete();

        // Profile di router ikut dibuang, supaya tidak tertinggal sebagai
        // objek yang tidak dimiliki paket mana pun.
        HapusProfilPppJob::dispatch($profile, $request->user()->id);

        PencatatAudit::catat('hapus_paket', "Menghapus paket {$nama} beserta profile {$profile}.", $paket);

        return response()->json(['message' => "Paket {$nama} dihapus. Profile di router sedang dibersihkan."]);
    }

    /** Memasang ulang profile di router tanpa mengubah data paket. */
    public function selaraskan(Request $request, PaketBandwidth $paket): JsonResponse
    {
        SelaraskanPaketJob::dispatch($paket->id, $request->user()->id);

        return response()->json(['message' => 'Penyelarasan profile diantrekan.']);
    }
}
