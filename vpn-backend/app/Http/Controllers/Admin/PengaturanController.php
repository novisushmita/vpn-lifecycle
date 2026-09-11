<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusAkun;
use App\Http\Controllers\Controller;
use App\Models\AkunVpn;
use App\Models\Drift;
use App\Services\PencatatAudit;
use App\Services\Pengaturan;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use App\Services\Vpn\AlokasiIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PengaturanController extends Controller
{
    public function index(): JsonResponse
    {
        $nilai = Pengaturan::semua();

        return response()->json([
            'daftar' => collect(config('pengaturan.bawaan'))->map(fn ($meta, $kunci) => [
                'kunci'   => $kunci,
                'label'   => $meta['label'],
                'bantuan' => $meta['bantuan'],
                'tipe'    => $meta['tipe'],
                'min'     => $meta['min'] ?? null,
                'max'     => $meta['max'] ?? null,
                'nilai'   => $nilai[$kunci] ?? $meta['nilai'],
                'bawaan'  => $meta['nilai'],
            ])->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $bawaan = config('pengaturan.bawaan');

        $data = $request->validate([
            'pengaturan'         => ['required', 'array'],
            'pengaturan.*.kunci' => ['required', Rule::in(array_keys($bawaan))],
            'pengaturan.*.nilai' => ['present'],
        ]);

        $diubah = [];

        foreach ($data['pengaturan'] as $baris) {
            $kunci = $baris['kunci'];
            $meta  = $bawaan[$kunci];
            $nilai = $baris['nilai'];

            if ($meta['tipe'] === 'angka') {
                $n = (int) $nilai;
                $min = $meta['min'] ?? 1;
                $max = $meta['max'] ?? PHP_INT_MAX;

                if ($n < $min || $n > $max) {
                    return response()->json([
                        'message' => "{$meta['label']} harus antara {$min} dan {$max}.",
                    ], 422);
                }
                $nilai = $n;
            }

            if ((string) $nilai === (string) Pengaturan::ambil($kunci)) {
                continue; // tidak berubah, tidak perlu dicatat
            }

            Pengaturan::simpan($kunci, $nilai, $request->user()->id);
            $diubah[$meta['label']] = $nilai;
        }

        if ($diubah === []) {
            return response()->json(['message' => 'Tidak ada perubahan.']);
        }

        PencatatAudit::catat(
            'ubah_pengaturan',
            'Mengubah pengaturan: ' . implode(', ', array_keys($diubah)) . '.',
            null,
            null,
            $diubah,
        );

        return response()->json([
            'message'       => 'Pengaturan disimpan.',
            'jumlah_diubah' => count($diubah),
        ]);
    }

    /**
     * Uji koneksi manajemen ke MikroTik saat ini juga. Satu permintaan ringan
     * (bukan ping 3 paket seperti VPS), aman dipanggil langsung dari request
     * HTTP.
     */
    public function tesKoneksiRouter(): JsonResponse
    {
        try {
            $info = RouterOsClient::dariConfig()->cekKoneksi();

            return response()->json(['tersambung' => true] + $info);
        } catch (RouterOsException $e) {
            return response()->json(['tersambung' => false, 'pesan' => $e->getMessage()], 200);
        }
    }

    /**
     * Akun terhapus yang IP-nya masih "terpakai" (AlokasiIp tidak pernah
     * daur ulang otomatis, lihat komentar di kelas itu). Urut dari yang
     * paling lama dihapus. `bisa_direklaim` hanya true kalau tidak ada
     * temuan drift terbuka untuk akun itu (belum tentu bersih di router).
     */
    public function kandidatReklaimIp(): JsonResponse
    {
        $akun = AkunVpn::onlyTrashed()
            ->where('status', StatusAkun::Dihapus)
            ->whereNotNull('ip_vpn')
            ->with('vps') // VPS bisa saja belum dihapus, jadi masih bisa dimuat lewat FK
            ->orderBy('deleted_at')
            ->get(['id', 'username', 'ip_vpn', 'deleted_at', 'vps_id']);

        $driftTerbuka = Drift::terbuka()
            ->whereIn('akun_vpn_id', $akun->pluck('id'))
            ->pluck('akun_vpn_id')
            ->unique();

        return response()->json([
            'ip_tersisa' => AlokasiIp::dariConfig()->jumlahTersisa(),
            'kandidat'   => $akun->map(fn ($a) => [
                'id'             => $a->id,
                'username'       => $a->username,
                'ip_vpn'         => $a->ip_vpn,
                'dihapus_pada'   => $a->deleted_at?->toIso8601String(),
                'vps'            => $a->vps?->nama ?? '-',
                'bisa_direklaim' => ! $driftTerbuka->contains($a->id),
            ])->values(),
        ]);
    }

    public function reklaimIp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $akunSemua = AkunVpn::onlyTrashed()
            ->where('status', StatusAkun::Dihapus)
            ->whereNotNull('ip_vpn')
            ->whereIn('id', $data['ids'])
            ->get();

        $driftTerbuka = Drift::terbuka()->whereIn('akun_vpn_id', $akunSemua->pluck('id'))->pluck('akun_vpn_id');

        $direklaim = [];
        $dilewati  = [];

        foreach ($akunSemua as $akun) {
            if ($driftTerbuka->contains($akun->id)) {
                $dilewati[] = $akun->username;
                continue;
            }

            $ipLama = $akun->ip_vpn;
            $akun->forceFill(['ip_vpn' => null])->save();
            $direklaim[] = "{$ipLama} ({$akun->username})";

            PencatatAudit::catat(
                'reklaim_ip',
                "Mereklaim IP {$ipLama} dari akun terhapus '{$akun->username}'.",
                $akun,
            );
        }

        if ($direklaim === []) {
            return response()->json([
                'message' => 'Tidak ada yang direklaim, semua yang dipilih masih punya temuan drift terbuka.',
            ], 422);
        }

        $pesan = 'IP dikembalikan ke pool: ' . implode(', ', $direklaim) . '.';
        if ($dilewati !== []) {
            $pesan .= ' Dilewati (masih ada drift terbuka): ' . implode(', ', $dilewati) . '.';
        }

        return response()->json(['message' => $pesan]);
    }
}
