<?php

namespace App\Jobs;

use App\Models\OperasiRouter;
use App\Models\Vps;
use App\Models\VpsHealthCheck;
use App\Services\Pengaturan;
use App\Services\RouterOs\RouterOsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ping satu VPS dari sisi router. Tiga paket dengan timeout memakan sekitar
 * tiga detik, jadi tidak boleh dijalankan di dalam request HTTP.
 *
 * Dipakai dua jalur: ping terjadwal (operasiId terisi, tercatat ke
 * operasi_router sebagai data Bab 4) dan ping manual "Ping sekarang"
 * (operasiId null, statusnya cuma dilacak lewat cache — sengaja tidak
 * menambah baris operasi_router supaya buku besar itu murni data otomatis).
 */
class PingVpsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly ?int $operasiId,
        public readonly int $vpsId,
        public readonly ?string $tokenCache = null,
    ) {}

    public function handle(): void
    {
        $operasi = $this->operasiId ? OperasiRouter::findOrFail($this->operasiId) : null;
        $vps     = Vps::findOrFail($this->vpsId);
        $mulai   = hrtime(true);

        $operasi?->update(['status' => 'berjalan', 'dimulai_pada' => now()]);
        if ($this->tokenCache) {
            Cache::put("vps_ping:{$this->tokenCache}", ['status' => 'berjalan'], 120);
        }

        try {
            $hasil = RouterOsClient::dariConfig()->ping($vps->alamat_ip);

            VpsHealthCheck::create([
                'vps_id'      => $vps->id,
                'sumber'      => 'router',
                'status'      => $hasil['status'],
                'rtt_avg_ms'  => $hasil['rtt_avg_ms'],
                'packet_loss' => $hasil['packet_loss'],
                'checked_at'  => now(),
            ]);

            // Satu kegagalan tidak cukup untuk menandai VPS bermasalah:
            // paket hilang sesekali itu wajar.
            $batas = Pengaturan::angka('batas_kegagalan_ping');
            $gagal = $hasil['status'] === 'down' ? $vps->gagal_berturut + 1 : 0;

            $vps->forceFill([
                'rtt_terakhir_ms' => $hasil['rtt_avg_ms'],
                'gagal_berturut'  => $gagal,
                'dicek_pada'      => now(),
                'status_terakhir' => $hasil['status'] === 'up'
                    ? 'up'
                    : ($gagal >= $batas ? 'down' : $vps->status_terakhir),
            ])->save();

            $durasi = (int) round((hrtime(true) - $mulai) / 1_000_000);

            $operasi?->update([
                'status' => 'sukses', 'hasil' => $hasil, 'selesai_pada' => now(), 'durasi_ms' => $durasi,
            ]);
            if ($this->tokenCache) {
                Cache::put("vps_ping:{$this->tokenCache}", ['status' => 'sukses', 'hasil' => $hasil], 120);
            }
        } catch (Throwable $e) {
            $operasi?->update([
                'status' => 'gagal', 'pesan_error' => $e->getMessage(), 'selesai_pada' => now(),
                'durasi_ms' => (int) round((hrtime(true) - $mulai) / 1_000_000),
            ]);
            if ($this->tokenCache) {
                Cache::put("vps_ping:{$this->tokenCache}", ['status' => 'gagal', 'pesan_error' => $e->getMessage()], 120);
            }

            throw $e;
        }
    }
}
