<?php

namespace App\Jobs;

use App\Models\OperasiRouter;
use App\Services\Pengaturan;
use App\Services\RouterOs\RouterOsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Merambatkan pengaturan yang menyentuh router. Lewat antrean seperti seluruh
 * operasi router lainnya, dan tercatat di buku besar operasi.
 */
class TerapkanPengaturanRouterJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly ?int $olehUserId = null) {}

    public function handle(): void
    {
        $router  = RouterOsClient::dariConfig();
        $mulai   = hrtime(true);
        $operasi = OperasiRouter::create([
            'jenis' => 'sinkron', 'status' => 'berjalan',
            'dimulai_pada' => now(), 'dipicu_oleh' => $this->olehUserId,
            'payload' => ['pengaturan' => 'dns_klien'],
        ]);

        try {
            $dns = trim((string) Pengaturan::ambil('dns_klien'));

            $router->perintah('ip/dns/set', [
                'servers'               => $dns,
                'allow-remote-requests' => 'yes',
            ]);

            $operasi->update([
                'status' => 'sukses', 'hasil' => ['dns' => $dns],
                'selesai_pada' => now(),
                'durasi_ms' => (int) round((hrtime(true) - $mulai) / 1_000_000),
            ]);
        } catch (Throwable $e) {
            $operasi->update([
                'status' => 'gagal', 'pesan_error' => $e->getMessage(),
                'selesai_pada' => now(),
                'durasi_ms' => (int) round((hrtime(true) - $mulai) / 1_000_000),
            ]);

            throw $e;
        }
    }
}
