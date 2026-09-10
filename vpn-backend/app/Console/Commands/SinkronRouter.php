<?php

namespace App\Console\Commands;

use App\Models\Drift;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\ProvisioningService;
use App\Services\Vpn\SinkronisasiService;
use Illuminate\Console\Command;

class SinkronRouter extends Command
{
    protected $signature = 'vpn:sinkron {--daftar : Tampilkan temuan yang masih terbuka}';

    protected $description = 'Membandingkan state basis data dengan state router (deteksi drift)';

    public function handle(RouterOsClient $router): int
    {
        $layanan = new SinkronisasiService($router, new ProvisioningService($router));
        $h = $layanan->periksa();

        $this->info("diperiksa={$h['diperiksa']} temuan={$h['temuan']} ditutup={$h['ditutup']}");

        if ($this->option('daftar') || $h['temuan'] > 0) {
            $baris = Drift::terbuka()->with('akunVpn')->get()->map(fn ($d) => [
                $d->id,
                $d->akunVpn?->username ?? '-',
                $d->jenis_objek . ($d->atribut ? ".{$d->atribut}" : ''),
                $d->jenis_drift,
                $d->nilai_db ?? '-',
                $d->nilai_router ?? '-',
            ])->all();

            if ($baris) {
                $this->table(['ID', 'Akun', 'Objek', 'Jenis', 'Basis data', 'Router'], $baris);
            }
        }

        return self::SUCCESS;
    }
}
