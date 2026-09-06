<?php

namespace App\Console\Commands;

use App\Models\Vps;
use App\Models\VpsHealthCheck;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use App\Services\Pengaturan;
use Illuminate\Console\Command;

/**
 * Ping terjadwal dari sisi ROUTER (CLAUDE.md 4.4).
 * Tidak pernah dijalankan di dalam request HTTP.
 */
class PingSemuaVps extends Command
{
    protected $signature = 'vps:ping';

    protected $description = 'Memeriksa ketersediaan seluruh VPS dari sisi router';

    public function handle(RouterOsClient $router): int
    {
        // Berapa kali ping harus gagal berturut-turut sebelum VPS ditandai
        // bermasalah. Satu kegagalan tidak cukup: paket hilang sesekali itu
        // wajar dan tidak berarti VPS-nya mati.
        $batasKegagalan = Pengaturan::angka('batas_kegagalan_ping');

        foreach (Vps::where('sedang_dihapus', false)->get() as $vps) {
            try {
                $hasil = $router->ping($vps->alamat_ip);
            } catch (RouterOsException $e) {
                $this->error("  {$vps->nama}: {$e->getMessage()}");
                continue;
            }

            VpsHealthCheck::create([
                'vps_id'      => $vps->id,
                'sumber'      => 'router',
                'status'      => $hasil['status'],
                'rtt_avg_ms'  => $hasil['rtt_avg_ms'],
                'packet_loss' => $hasil['packet_loss'],
                'checked_at'  => now(),
            ]);

            $gagal = $hasil['status'] === 'down' ? $vps->gagal_berturut + 1 : 0;

            $vps->forceFill([
                'rtt_terakhir_ms' => $hasil['rtt_avg_ms'],
                'gagal_berturut'  => $gagal,
                'dicek_pada'      => now(),
                'status_terakhir' => $hasil['status'] === 'up'
                    ? 'up'
                    : ($gagal >= $batasKegagalan ? 'down' : $vps->status_terakhir),
            ])->save();

            $this->line(sprintf('  %-20s %-5s %s', $vps->nama, $vps->status_terakhir,
                $hasil['rtt_avg_ms'] ? $hasil['rtt_avg_ms'] . ' ms' : 'loss ' . $hasil['packet_loss'] . '%'));
        }

        return self::SUCCESS;
    }
}
