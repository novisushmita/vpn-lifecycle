<?php

namespace App\Services\Vpn;

use App\Models\AkunVpn;
use App\Models\PaketBandwidth;
use App\Enums\StatusAkun;
use App\Models\Pengajuan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menerbitkan baris akun_vpn dari pengajuan yang disetujui.
 * Belum menyentuh router sama sekali — status masih menunggu_provision.
 */
class PenerbitAkun
{
    public function __construct(private readonly AlokasiIp $alokasi) {}

    public function dariPengajuan(Pengajuan $pengajuan, PaketBandwidth $paket, ?int $olehUserId = null): AkunVpn
    {
        return DB::transaction(function () use ($pengajuan, $paket, $olehUserId) {
            // Kunci baris supaya dua admin tidak menyetujui pengajuan yang sama
            // secara bersamaan dan menerbitkan dua akun.
            $pengajuan = Pengajuan::whereKey($pengajuan->id)->lockForUpdate()->firstOrFail();

            if ($akun = AkunVpn::withTrashed()->where('pengajuan_id', $pengajuan->id)->first()) {
                return $akun; // idempoten
            }

            $akun = AkunVpn::create([
                'pengajuan_id'       => $pengajuan->id,
                'vps_id'             => $pengajuan->vps_id,
                'paket_bandwidth_id' => $paket->id,
                'username'           => $this->username($pengajuan->nama),
                'password'           => $this->password(),
                'ip_vpn'             => $this->alokasi->berikutnya(),
                'mulai_pada'         => $pengajuan->durasi_mulai,
                'selesai_pada'       => $pengajuan->durasi_selesai,
            ]);

            // Status di-set eksplisit, tidak mengandalkan default kolom:
            // objek hasil create() tidak memuat nilai default dari basis data.
            $akun->forceFill([
                'status'      => StatusAkun::MenungguProvision,
                'dibuat_oleh' => $olehUserId,
            ])->save();

            return $akun;
        });
    }

    public function username(string $nama): string
    {
        $dasar = Str::of($nama)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '.')
            ->trim('.')->limit(20, '')->value();

        $dasar = $dasar !== '' ? $dasar : 'pengguna';

        do {
            $kandidat = $dasar . '.' . Str::lower(Str::random(4));
        } while (AkunVpn::withTrashed()->where('username', $kandidat)->exists());

        return $kandidat;
    }

    /** Tanpa karakter yang mudah tertukar saat dibacakan ke pengguna. */
    public function password(int $panjang = 14): string
    {
        $huruf = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $hasil = '';

        for ($i = 0; $i < $panjang; $i++) {
            $hasil .= $huruf[random_int(0, strlen($huruf) - 1)];
        }

        return $hasil;
    }
}
