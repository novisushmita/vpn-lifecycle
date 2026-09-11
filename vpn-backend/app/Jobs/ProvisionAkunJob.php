<?php

namespace App\Jobs;

use App\Mail\KredensialVpn;
use App\Models\AkunVpn;
use App\Services\Pengaturan;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Provisioning berjalan di queue, bukan di dalam request HTTP: operasi ke
 * router bisa memakan beberapa ratus milidetik sampai timeout, dan admin
 * tidak boleh menunggu.
 */
class ProvisionAkunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Jeda antar percobaan, dalam detik: 15, 60, 300, 900.
     * Percobaan terakhir jatuh sekitar 21 menit setelah yang pertama.
     *
     * Rentang selebar ini disengaja. Router yang dinyalakan ulang butuh
     * sekitar satu menit, dan pemeliharaan singkat bisa beberapa menit. Dengan
     * jendela lama, gangguan sesaat pulih sendiri tanpa campur tangan admin —
     * dan admin memang tidak sedang menunggui layar setelah menekan Setujui.
     */
    public array $backoff = [15, 60, 300, 900];

    public function __construct(
        public readonly int $akunVpnId,
        public readonly ?int $olehUserId = null,
    ) {}

    public function handle(): void
    {
        $akun = AkunVpn::with(['vps', 'paketBandwidth'])->find($this->akunVpnId);

        if (! $akun) {
            return; // akun sudah dihapus sebelum job sempat jalan
        }

        $akun = (new ProvisioningService(RouterOsClient::dariConfig()))
            ->provision($akun, $this->olehUserId);

        // Dikirim hanya setelah objek benar-benar terpasang di router.
        // Mengirim lebih awal berarti pemohon menerima kredensial yang belum
        // tentu bisa dipakai.
        $akun->loadMissing(['pengajuan', 'vps']);

        Mail::to($akun->pengajuan->email)->queue(new KredensialVpn(
            $akun,
            $akun->password,
            (string) (Pengaturan::ambil('alamat_server_vpn')
                ?: config('routeros.vpn_server')
                ?: parse_url((string) config('routeros.base_url'), PHP_URL_HOST)),
            (string) config('routeros.ipsec_psk'),
        ));
    }
}
