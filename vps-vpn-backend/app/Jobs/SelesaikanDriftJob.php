<?php

namespace App\Jobs;

use App\Models\Drift;
use App\Models\OperasiRouter;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\ProvisioningService;
use App\Services\Vpn\SinkronisasiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rekonsiliasi satu temuan drift.
 *
 * Arah push memasang ulang objek di router dan bisa menyentuh tiga menu
 * sekaligus, jadi tidak dijalankan di dalam request HTTP.
 */
class SelesaikanDriftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $operasiId,
        public readonly int $driftId,
        public readonly string $resolusi,
        public readonly ?int $olehUserId = null,
    ) {}

    public function handle(): void
    {
        $operasi = OperasiRouter::findOrFail($this->operasiId);
        $drift   = Drift::findOrFail($this->driftId);
        $mulai   = hrtime(true);

        $operasi->update(['status' => 'berjalan', 'dimulai_pada' => now()]);

        $router = RouterOsClient::dariConfig();

        try {
            (new SinkronisasiService($router, new ProvisioningService($router)))
                ->selesaikan($drift, $this->resolusi, $this->olehUserId);

            $operasi->update([
                'status' => 'sukses',
                'hasil'  => ['drift_id' => $drift->id, 'resolusi' => $this->resolusi],
                'selesai_pada' => now(),
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

    public function failed(?Throwable $e): void
    {
        OperasiRouter::whereKey($this->operasiId)
            ->whereIn('status', ['antre', 'berjalan'])
            ->update([
                'status' => 'gagal',
                'pesan_error' => $e?->getMessage() ?? 'Pekerjaan terhenti tanpa keterangan.',
                'selesai_pada' => now(),
            ]);
    }
}
