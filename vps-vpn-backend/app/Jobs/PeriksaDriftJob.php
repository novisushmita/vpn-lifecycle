<?php

namespace App\Jobs;

use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\ProvisioningService;
use App\Services\Vpn\SinkronisasiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pemeriksaan drift memindai seluruh akun dan tiga menu firewall sekaligus.
 * Ini operasi paling berat terhadap router, sehingga paling tidak boleh
 * dijalankan di dalam request HTTP.
 */
class PeriksaDriftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly ?int $olehUserId = null) {}

    public function handle(): void
    {
        $router = RouterOsClient::dariConfig();

        (new SinkronisasiService($router, new ProvisioningService($router)))->periksa();
    }
}
