<?php

namespace App\Console\Commands;

use App\Jobs\PingVpsJob;
use App\Models\OperasiRouter;
use App\Models\Vps;
use Illuminate\Console\Command;

/**
 * Ping terjadwal dari sisi ROUTER (CLAUDE.md 4.4).
 *
 * Dispatch satu PingVpsJob per VPS ke antrean, pola yang sama dengan tombol
 * "Ping sekarang" (VpsController::ping) — supaya ping terjadwal juga tercatat
 * di operasi_router (dipicu_oleh=null menandai ini otomatis, bukan manual)
 * dan ikut jadi data durasi/keandalan di Bab 4 skripsi. Perintah ini sendiri
 * hanya mengantrekan; ping sebenarnya jalan di worker lewat job.
 */
class PingSemuaVps extends Command
{
    protected $signature = 'vps:ping';

    protected $description = 'Mengantrekan pemeriksaan ketersediaan seluruh VPS dari sisi router';

    public function handle(): int
    {
        $vps = Vps::where('sedang_dihapus', false)->get();

        foreach ($vps as $vp) {
            $operasi = OperasiRouter::create([
                'jenis'   => 'ping',
                'vps_id'  => $vp->id,
                'status'  => 'antre',
                'payload' => ['alamat_ip' => $vp->alamat_ip],
            ]);

            PingVpsJob::dispatch($operasi->id, $vp->id);
        }

        $this->info("Diantrekan {$vps->count()} pemeriksaan VPS.");

        return self::SUCCESS;
    }
}
