<?php

namespace App\Services\Vpn;

use App\Enums\StatusAkun;
use App\Models\OperasiRouter;
use App\Models\Vps;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Penghapusan VPS berantai (CLAUDE.md 4.4b).
 *
 * Menghapus VPS berarti setiap akun yang terikat padanya harus di-deprovision
 * penuh dari router LEBIH DULU. Cascade basis data tidak dipakai: ia hanya
 * menghapus baris akun_vpn sementara /ppp secret, address-list, dan aturan
 * firewall-nya tetap hidup di router — akun jadi tidak terlacak tapi masih
 * bisa dipakai konek.
 */
class PenghapusVps
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    /** Ringkasan dampak, untuk ditampilkan sebelum admin menekan tombol. */
    public function dampak(Vps $vps): array
    {
        $akun = $vps->akunVpn()->with('pengajuan:id,nomor,nama')->get();

        return [
            'vps'                => ['id' => $vps->id, 'nama' => $vps->nama, 'alamat_ip' => $vps->alamat_ip],
            'sedang_dihapus'     => (bool) $vps->sedang_dihapus,
            'jumlah_akun'        => $akun->count(),
            'akun'               => $akun->map(fn ($a) => [
                'id'       => $a->id,
                'username' => $a->username,
                'status'   => $a->status?->value,
                'pemohon'  => $a->pengajuan?->nama,
            ])->all(),
            'pengajuan_menunggu' => $vps->pengajuan()->whereIn('status', ['diajukan', 'ditinjau'])->count(),
            'operasi_berjalan'   => $this->operasiBerjalan($vps),
        ];
    }

    /**
     * @return array{akun_dibersihkan:int, akun_gagal:int, pengajuan_dibatalkan:int, vps_terhapus:bool, gagal:array}
     */
    public function jalankan(Vps $vps, ?int $olehUserId = null): array
    {
        // 1. Tolak bila masih ada operasi berjalan untuk VPS ini.
        if ($this->operasiBerjalan($vps) > 0) {
            throw new RuntimeException(
                'Masih ada operasi router yang berjalan untuk VPS ini. Tunggu sampai selesai lalu ulangi.'
            );
        }

        // 2. Tandai lebih dulu supaya VPS langsung hilang dari endpoint publik
        //    dan tidak ada pengajuan baru yang masuk selama pembersihan.
        $vps->forceFill(['sedang_dihapus' => true])->save();

        // 3. Batalkan pengajuan yang masih menunggu.
        $dibatalkan = 0;
        foreach ($vps->pengajuan()->whereIn('status', ['diajukan', 'ditinjau'])->get() as $pengajuan) {
            $pengajuan->forceFill([
                'status'           => 'ditolak',
                'alasan_penolakan' => "VPS {$vps->nama} dihapus dari sistem, sehingga pengajuan tidak dapat diproses.",
                'ditinjau_oleh'    => $olehUserId,
                'ditinjau_pada'    => now(),
            ])->save();
            $dibatalkan++;
        }

        // 4. Deprovision penuh setiap akun.
        $bersih = 0;
        $gagal  = [];

        foreach ($vps->akunVpn()->get() as $akun) {
            try {
                $this->provisioning->hapus($akun, 'vps_dihapus', $olehUserId);
                $bersih++;
            } catch (Throwable $e) {
                $gagal[] = ['username' => $akun->username, 'pesan' => $e->getMessage()];
            }
        }

        // 5. VPS hanya dihapus bila SEMUA akun berhasil dibersihkan. Bila VPS
        //    dihapus lebih dulu, sistem kehilangan referensi ke akun yang
        //    belum sempat dibersihkan dan objek yatim di router jadi tidak
        //    punya pemilik untuk dilacak.
        $terhapus = false;
        if ($gagal === []) {
            DB::transaction(function () use ($vps, &$terhapus) {
                $vps->delete();
                $terhapus = true;
            });
        }

        return [
            'akun_dibersihkan'     => $bersih,
            'akun_gagal'           => count($gagal),
            'pengajuan_dibatalkan' => $dibatalkan,
            'vps_terhapus'         => $terhapus,
            'gagal'                => $gagal,
        ];
    }

    private function operasiBerjalan(Vps $vps): int
    {
        $idAkun = $vps->akunVpn()->pluck('id');

        return OperasiRouter::whereIn('status', ['antre', 'berjalan'])
            ->where(fn ($q) => $q->where('vps_id', $vps->id)->orWhereIn('akun_vpn_id', $idAkun))
            ->count();
    }
}
