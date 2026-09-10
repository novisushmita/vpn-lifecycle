<?php

namespace App\Services\Vpn;

use App\Models\Pengajuan;
use Illuminate\Support\Facades\DB;

/**
 * Menerbitkan nomor pengajuan VPN-YYYY-NNNN.
 *
 * Memakai penguncian tabel di dalam transaksi. Menghitung dari jumlah baris
 * (seperti versi dummy di frontend) menimbulkan race condition: dua pengajuan
 * bersamaan akan mendapat nomor yang sama. Unique constraint pada kolom nomor
 * adalah jaring pengaman terakhir.
 */
class NomorPengajuan
{
    public function berikutnya(): string
    {
        $tahun = now()->year;

        return DB::transaction(function () use ($tahun) {
            $terakhir = Pengajuan::where('nomor', 'like', "VPN-{$tahun}-%")
                ->lockForUpdate()
                ->orderByDesc('nomor')
                ->value('nomor');

            $urutan = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

            return sprintf('VPN-%d-%04d', $tahun, $urutan);
        });
    }
}
