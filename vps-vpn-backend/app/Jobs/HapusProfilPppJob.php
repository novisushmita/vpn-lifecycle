<?php

namespace App\Jobs;

use App\Models\OperasiRouter;
use App\Services\RouterOs\RouterOsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Membuang PPP profile milik paket yang dihapus.
 *
 * Tanpa ini, menghapus paket hanya membuang baris basis data sementara
 * profile-nya tetap hidup di router: sisa yang tidak dimiliki siapa pun,
 * persis jenis objek yatim yang dicegah sistem ini.
 */
class HapusProfilPppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly string $namaProfile,
        public readonly ?int $olehUserId = null,
    ) {}

    public function handle(): void
    {
        $router  = RouterOsClient::dariConfig();
        $mulai   = hrtime(true);
        $operasi = OperasiRouter::create([
            'jenis' => 'sinkron', 'status' => 'berjalan',
            'dimulai_pada' => now(), 'dipicu_oleh' => $this->olehUserId,
            'payload' => ['hapus_profile' => $this->namaProfile],
        ]);

        try {
            $terhapus = false;

            foreach ($router->daftar('ppp/profile') as $p) {
                if (($p['name'] ?? null) === $this->namaProfile) {
                    $router->hapus('ppp/profile', $p['.id']);
                    $terhapus = true;
                    break;
                }
            }

            $operasi->update([
                'status' => 'sukses',
                'hasil'  => ['terhapus' => $terhapus],
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
