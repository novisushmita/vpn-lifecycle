<?php

namespace App\Console\Commands;

use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\PencatatSesi;
use Illuminate\Console\Command;

class PollingSesi extends Command
{
    protected $signature = 'vpn:polling-sesi';

    protected $description = 'Menyelaraskan log sesi dengan daftar sesi aktif di router';

    public function handle(RouterOsClient $router): int
    {
        $h = (new PencatatSesi($router))->sinkronkan();

        $this->line("sesi dibuka={$h['dibuka']} ditutup={$h['ditutup']} diperbarui={$h['diperbarui']}");

        return self::SUCCESS;
    }
}
