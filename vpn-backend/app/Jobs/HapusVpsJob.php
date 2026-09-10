<?php

namespace App\Jobs;

use App\Models\Vps;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\PenghapusVps;
use App\Services\Vpn\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Penghapusan berantai bisa menyentuh banyak akun sekaligus; setiap akun
 * memerlukan beberapa panggilan ke router. Tidak boleh dijalankan di dalam
 * request HTTP.
 */
class HapusVpsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // idempoten per akun, tetapi retry ditangani admin

    public function __construct(
        public readonly int $vpsId,
        public readonly ?int $olehUserId = null,
    ) {}

    public function handle(): void
    {
        $vps = Vps::find($this->vpsId);

        if (! $vps) {
            return;
        }

        $router = RouterOsClient::dariConfig();

        (new PenghapusVps(new ProvisioningService($router)))
            ->jalankan($vps, $this->olehUserId);
    }
}
