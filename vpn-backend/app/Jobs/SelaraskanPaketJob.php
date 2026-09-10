<?php

namespace App\Jobs;

use App\Models\OperasiRouter;
use App\Models\PaketBandwidth;
use App\Services\RouterOs\RouterOsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Memastikan PPP profile milik sebuah paket benar-benar ada di router dengan
 * rate-limit yang sesuai.
 *
 * Tanpa ini, menambah paket lewat web hanya menambah baris basis data:
 * provisioning akan gagal begitu ada akun memakainya, karena profile yang
 * dirujuk tidak pernah dibuat di router.
 */
class SelaraskanPaketJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $paketId,
        public readonly ?int $olehUserId = null,
    ) {}

    public function handle(): void
    {
        $paket = PaketBandwidth::find($this->paketId);

        if (! $paket) {
            return;
        }

        $router  = RouterOsClient::dariConfig();
        $mulai   = hrtime(true);
        $operasi = OperasiRouter::create([
            'jenis' => 'sinkron', 'status' => 'berjalan',
            'dimulai_pada' => now(), 'dipicu_oleh' => $this->olehUserId,
            'payload' => [
                'paket'       => $paket->nama,
                'ppp_profile' => $paket->ppp_profile,
                'rate_limit'  => $paket->rateLimit(),
            ],
        ]);

        try {
            $adaId = null;
            foreach ($router->daftar('ppp/profile') as $p) {
                if (($p['name'] ?? null) === $paket->ppp_profile) {
                    $adaId = $p['.id'];
                    break;
                }
            }

            $atribut = [
                'rate-limit'     => $paket->rateLimit(),
                'local-address'  => config('routeros.local_address', '10.10.20.1'),
                'remote-address' => config('routeros.ip_pool'),
                'use-encryption' => 'yes',
                'change-tcp-mss' => 'yes',
                'dns-server'     => config('routeros.local_address', '10.10.20.1'),
            ];

            if ($adaId) {
                $router->ubah('ppp/profile', $adaId, $atribut);
                $tindakan = 'diperbarui';
            } else {
                $router->buat('ppp/profile', $atribut + ['name' => $paket->ppp_profile]);
                $tindakan = 'dibuat';
            }

            $operasi->update([
                'status' => 'sukses',
                'hasil'  => ['profile' => $tindakan],
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
