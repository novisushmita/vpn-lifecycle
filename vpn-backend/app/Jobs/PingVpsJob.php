<?php

namespace App\Jobs;

use App\Models\OperasiRouter;
use App\Models\Vps;
use App\Models\VpsHealthCheck;
use App\Services\Pengaturan;
use App\Services\RouterOs\RouterOsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Ping satu VPS dari sisi router. Tiga paket dengan timeout memakan sekitar
 * tiga detik, jadi tidak boleh dijalankan di dalam request HTTP.
 */
class PingVpsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $operasiId,
        public readonly int $vpsId,
    ) {}

    public function handle(): void
    {
        $operasi = OperasiRouter::findOrFail($this->operasiId);
        $vps     = Vps::findOrFail($this->vpsId);
        $mulai   = hrtime(true);

        $operasi->update(['status' => 'berjalan', 'dimulai_pada' => now()]);

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

            $operasi->update([
                'status' => 'sukses', 'hasil' => $hasil, 'selesai_pada' => now(),
                'durasi_ms' => (int) round((hrtime(true) - $mulai) / 1_000_000),
            ]);
        } catch (Throwable $e) {
            $operasi->update([
                'status' => 'gagal', 'pesan_error' => $e->getMessage(), 'selesai_pada' => now(),
                'durasi_ms' => (int) round((hrtime(true) - $mulai) / 1_000_000),
            ]);

            throw $e;
        }
    }
}
