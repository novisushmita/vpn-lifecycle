<?php

namespace App\Jobs;

use App\Mail\PerpanjanganDisetujui;
use App\Models\AkunVpn;
use App\Models\OperasiRouter;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Operasi siklus hidup akun yang dijalankan lewat antrean.
 *
 * Satu operasi ke router dapat memakan beberapa ratus milidetik sampai
 * timeout penuh bila router lambat. Menjalankannya di dalam request HTTP
 * membuat dashboard menggantung dan, pada beberapa permintaan bersamaan,
 * menghabiskan worker PHP-FPM (CLAUDE.md 6.2).
 */
class OperasiAkunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $operasiId,
        public readonly int $akunVpnId,
        public readonly string $jenis,
        public readonly array $data = [],
    ) {}

    public function handle(): void
    {
        $operasi = OperasiRouter::findOrFail($this->operasiId);
        $akun    = AkunVpn::with(['vps', 'paketBandwidth'])->findOrFail($this->akunVpnId);

        $layanan = (new ProvisioningService(RouterOsClient::dariConfig()))->pakaiOperasi($operasi);

        $hasil = match ($this->jenis) {
            'disable' => $layanan->nonaktifkan($akun, $operasi->dipicu_oleh),
            'enable'  => $layanan->aktifkan($akun, $operasi->dipicu_oleh),
            'hapus'   => $layanan->hapus($akun, $this->data['alasan'] ?? 'admin', $operasi->dipicu_oleh),
            'extend'  => $layanan->perpanjang($akun, $this->data['selesai_pada'], $operasi->dipicu_oleh),
            default   => throw new RuntimeException("Jenis operasi tidak dikenal: {$this->jenis}"),
        };

        // Dikirim di sini, bukan di controller, karena baru sampai titik ini
        // perpanjangan benar-benar berlaku di router. Tanpa ini pemohon tidak
        // pernah tahu perpanjangannya disetujui kecuali membuka cek status.
        if ($this->jenis === 'extend') {
            $hasil->loadMissing('pengajuan');
            Mail::to($hasil->pengajuan->email)->queue(new PerpanjanganDisetujui($hasil));
        }
    }

    public function failed(?Throwable $e): void
    {
        OperasiRouter::whereKey($this->operasiId)
            ->whereIn('status', ['antre', 'berjalan'])
            ->update([
                'status'       => 'gagal',
                'pesan_error'  => $e?->getMessage() ?? 'Pekerjaan terhenti tanpa keterangan.',
                'selesai_pada' => now(),
            ]);
    }
}
