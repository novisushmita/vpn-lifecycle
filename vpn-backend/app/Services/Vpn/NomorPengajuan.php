<?php

namespace App\Services\Vpn;

use App\Models\Pengajuan;

/**
 * Menerbitkan nomor pengajuan VPN-YYYY-XX99 (2 huruf acak + 2 angka acak),
 * sengaja tidak berurutan supaya nomor pengajuan tidak menebak jumlah
 * pengajuan yang sudah masuk.
 *
 * Coba beberapa kali sampai dapat yang belum dipakai; unique constraint pada
 * kolom nomor tetap jadi jaring pengaman terakhir kalau ada tabrakan.
 */
class NomorPengajuan
{
    public function berikutnya(): string
    {
        $tahun  = now()->year;
        $huruf  = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // tanpa I/O, gampang tertukar 1/0

        for ($percobaan = 0; $percobaan < 20; $percobaan++) {
            $nomor = sprintf(
                'VPN-%d-%s%s%d%d',
                $tahun,
                $huruf[random_int(0, strlen($huruf) - 1)],
                $huruf[random_int(0, strlen($huruf) - 1)],
                random_int(0, 9),
                random_int(0, 9),
            );

            if (! Pengajuan::where('nomor', $nomor)->exists()) {
                return $nomor;
            }
        }

        throw new \RuntimeException('Gagal membuat nomor pengajuan unik setelah beberapa percobaan.');
    }
}
