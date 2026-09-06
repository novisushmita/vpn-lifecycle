<?php

namespace App\Console\Commands;

use App\Enums\StatusAkun;
use App\Mail\PeringatanKedaluwarsa;
use App\Models\AkunVpn;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Pengaturan;
use App\Services\Vpn\ProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Fase kedaluwarsa (CLAUDE.md 4.10). Dijalankan scheduler harian.
 *
 *   H-3   -> tandai akan_kedaluwarsa + kirim peringatan
 *   lewat -> nonaktifkan di router, putuskan sesi
 *   H+30  -> hapus permanen, sisakan audit
 */
class ProsesKedaluwarsa extends Command
{
    protected $signature = 'vpn:kedaluwarsa {--dry-run : Tampilkan yang akan diproses tanpa mengubah apa pun}';

    protected $description = 'Memproses peringatan H-3, kedaluwarsa, dan pembersihan H+30';

    public function handle(RouterOsClient $router): int
    {
        $kering = (bool) $this->option('dry-run');
        $prov   = new ProvisioningService($router);
        $hariIni = now()->startOfDay();

        $hariPeringatan  = Pengaturan::angka('hari_peringatan_kedaluwarsa');
        $hariPembersihan = Pengaturan::angka('hari_pembersihan_kedaluwarsa');

        // --- 1. Peringatan H-3 ---
        $akanHabis = AkunVpn::where('status', StatusAkun::Aktif)
            ->whereNull('peringatan_h3_dikirim_pada')
            ->whereDate('selesai_pada', '<=', $hariIni->copy()->addDays($hariPeringatan))
            ->whereDate('selesai_pada', '>=', $hariIni)
            ->get();

        foreach ($akanHabis as $akun) {
            $this->line("  H-{$hariPeringatan} {$akun->username} (selesai {$akun->selesai_pada->toDateString()})");

            if ($kering) {
                continue;
            }

            $akun->forceFill([
                'status'                     => StatusAkun::AkanKedaluwarsa,
                'peringatan_h3_dikirim_pada' => now(),
            ])->save();

            // Kolom peringatan_h3_dikirim_pada di atas yang mencegah email
            // terkirim berulang setiap kali perintah ini dijalankan.
            $akun->loadMissing('pengajuan');
            $sisa = (int) now()->startOfDay()->diffInDays($akun->selesai_pada, false);

            Mail::to($akun->pengajuan->email)->queue(
                new PeringatanKedaluwarsa($akun, max(0, $sisa))
            );
        }

        // --- 2. Sudah lewat tanggal selesai ---
        $lewat = AkunVpn::whereIn('status', [StatusAkun::Aktif, StatusAkun::AkanKedaluwarsa])
            ->whereDate('selesai_pada', '<', $hariIni)
            ->get();

        $gagal = 0;
        foreach ($lewat as $akun) {
            $this->line("  EXPIRE {$akun->username} (selesai {$akun->selesai_pada->toDateString()})");

            if ($kering) {
                continue;
            }

            try {
                $prov->kedaluwarsakan($akun);
            } catch (Throwable $e) {
                $gagal++;
                $this->error("         gagal: {$e->getMessage()}");
            }
        }

        // --- 3. Pembersihan H+30 ---
        $bersihkan = AkunVpn::where('status', StatusAkun::Kedaluwarsa)
            ->whereDate('selesai_pada', '<', $hariIni->copy()->subDays($hariPembersihan))
            ->get();

        foreach ($bersihkan as $akun) {
            $this->line("  HAPUS  {$akun->username} (berakhir lebih dari {$hariPembersihan} hari lalu)");

            if ($kering) {
                continue;
            }

            try {
                $prov->hapus($akun, 'kedaluwarsa');
            } catch (Throwable $e) {
                $gagal++;
                $this->error("         gagal: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            '%speringatan=%d kedaluwarsa=%d dihapus=%d gagal=%d',
            $kering ? '[dry-run] ' : '',
            $akanHabis->count(), $lewat->count(), $bersihkan->count(), $gagal,
        ));

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
