<?php

namespace App\Jobs;

use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Menyegarkan status koneksi router ke cache.
 *
 * Dashboard hanya membaca cache. Tanpa ini, membuka dashboard saat router mati
 * berarti menunggu timeout penuh sebelum halaman muncul, dan justru saat router
 * bermasalah itulah dashboard paling dibutuhkan.
 */
class CekKoneksiRouterJob implements ShouldQueue
{
    use Queueable;

    public const KUNCI = 'router.status';

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        try {
            $info = RouterOsClient::dariConfig()->cekKoneksi();
            $data = ['tersambung' => true] + $info;
        } catch (RouterOsException $e) {
            $data = ['tersambung' => false, 'pesan' => $e->getMessage()];
        }

        Cache::put(self::KUNCI, $data + ['diperiksa_pada' => now()->toIso8601String()], 600);
    }
}
