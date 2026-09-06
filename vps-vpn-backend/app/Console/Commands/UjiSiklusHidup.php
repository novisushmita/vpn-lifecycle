<?php

namespace App\Console\Commands;

use App\Models\AkunVpn;
use App\Models\OperasiRouter;
use App\Models\PaketBandwidth;
use App\Models\Pengajuan;
use App\Models\Vps;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\AlokasiIp;
use App\Services\Vpn\PenerbitAkun;
use App\Services\Vpn\ProvisioningService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Menjalankan satu siklus hidup akun penuh terhadap router sungguhan:
 * terbitkan -> provision -> disable -> enable -> hapus, lalu memeriksa
 * tidak ada objek yatim yang tertinggal.
 *
 * Dipakai sebagai demo dan sebagai alat pengukuran Bab 4 (durasi tiap operasi
 * diambil dari tabel operasi_router).
 */
class UjiSiklusHidup extends Command
{
    protected $signature = 'vpn:uji-siklus
                            {--vps=10.10.10.11 : Alamat VPS tujuan untuk pengujian}
                            {--simpan : Jangan hapus data uji di akhir}';

    protected $description = 'Menguji satu siklus hidup akun VPN penuh terhadap router';

    public function handle(): int
    {
        $router = RouterOsClient::dariConfig();
        $prov   = new ProvisioningService($router);
        $terbit = new PenerbitAkun(AlokasiIp::dariConfig());

        $paket = PaketBandwidth::where('nama', 'Standar')->first();
        if (! $paket) {
            $this->error('Paket bandwidth belum di-seed. Jalankan: php artisan db:seed');

            return self::FAILURE;
        }

        $tanda = 'UJI-' . now()->format('His');
        $vps   = Vps::create([
            'nama'       => $tanda,
            'alamat_ip'  => $this->option('vps'),
            'keterangan' => 'VPS sementara untuk uji siklus hidup',
        ]);

        $pengajuan = new Pengajuan([
            'nama'           => 'Budi Santoso',
            'identitas'      => '198701012010011001',
            'instansi'       => 'Dinas Komunikasi dan Informatika',
            'email'          => 'budi@instansi.go.id',
            'vps_id'         => $vps->id,
            'keperluan'      => 'Maintenance server',
            'durasi_mulai'   => now()->toDateString(),
            'durasi_selesai' => now()->addDays(30)->toDateString(),
        ]);
        $pengajuan->nomor  = 'VPN-' . now()->year . '-' . $tanda;
        $pengajuan->status = 'disetujui';
        $pengajuan->save();

        $akun = null;

        try {
            $this->tahap('1. Terbitkan akun');
            $akun = $terbit->dariPengajuan($pengajuan, $paket);
            $this->hasil([
                'username' => $akun->username,
                'ip_vpn'   => $akun->ip_vpn,
                'status'   => $akun->status->value,
            ]);

            $this->tahap('2. Provision ke router (3 langkah)');
            $akun = $prov->provision($akun);
            $this->hasil([
                'status'       => $akun->status->value,
                'ppp secret'   => $akun->router_secret_id,
                'address-list' => $akun->router_addresslist_id,
                'firewall'     => $akun->router_firewall_id,
            ]);

            $this->tahap('3. Verifikasi objek di router');
            $sec = $router->ambil('ppp/secret', $akun->router_secret_id);
            $fw  = $router->ambil('ip/firewall/filter', $akun->router_firewall_id);
            $al  = $router->ambil('ip/firewall/address-list', $akun->router_addresslist_id);
            $this->hasil([
                'secret'       => "{$sec['name']} · profile={$sec['profile']} · remote={$sec['remote-address']}",
                'address-list' => "{$al['list']} berisi {$al['address']}",
                'firewall'     => "src={$fw['src-address']} → list={$fw['dst-address-list']} action={$fw['action']}",
            ]);

            $this->tahap('4. Urutan aturan forward — accept harus DI ATAS drop');
            $baris = [];
            foreach ($router->daftar('ip/firewall/filter') as $r) {
                if (($r['chain'] ?? '') !== 'forward') {
                    continue;
                }
                $baris[] = [
                    $r['action'] ?? '-',
                    $r['src-address'] ?? '-',
                    $r['dst-address-list'] ?? ($r['dst-address'] ?? '-'),
                    $r['comment'] ?? '',
                ];
            }
            $this->table(['Action', 'Src', 'Dst', 'Comment'], $baris);

            $this->tahap('5. Disable — secret dinonaktifkan + sesi diputus');
            $akun = $prov->nonaktifkan($akun);
            $this->hasil([
                'status'           => $akun->status->value,
                'disabled(router)' => $router->ambil('ppp/secret', $akun->router_secret_id)['disabled'],
            ]);

            $this->tahap('6. Enable — objek hilang dipasang ulang bila perlu');
            $akun = $prov->aktifkan($akun);
            $this->hasil([
                'status'           => $akun->status->value,
                'disabled(router)' => $router->ambil('ppp/secret', $akun->router_secret_id)['disabled'],
            ]);

            $this->tahap('7. Hapus — urutan dibalik 3 → 2 → 1');
            $idAkun = $akun->id;
            $akun   = $prov->hapus($akun, 'admin');
            $this->hasil([
                'status'      => $akun->status->value,
                'soft delete' => $akun->trashed() ? 'ya' : 'tidak',
            ]);

            $this->tahap('8. Objek yatim yang tertinggal di router');
            $yatim = $this->hitungYatim($router, $idAkun);
            $this->hasil(['objek bertanda akun ini' => $yatim]);

            if ($yatim > 0) {
                $this->error("  {$yatim} objek tertinggal — deprovisioning tidak bersih.");
            } else {
                $this->info('  Bersih. Tidak ada objek tertinggal.');
            }

            $this->tahap('9. Buku besar operasi (sumber data Bab 4)');
            $this->table(
                ['Jenis', 'Status', 'Durasi (ms)'],
                OperasiRouter::where('akun_vpn_id', $idAkun)->orderBy('id')
                    ->get(['jenis', 'status', 'durasi_ms'])
                    ->map(fn ($o) => [$o->jenis, $o->status, $o->durasi_ms])
                    ->all(),
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('GAGAL: ' . $e->getMessage());

            if ($akun) {
                $this->warn('Status akun terakhir: ' . $akun->fresh()?->status->value);
            }

            $this->bersihkan($akun, $pengajuan, $vps);

            return self::FAILURE;
        }

        if (! $this->option('simpan')) {
            $this->bersihkan($akun, $pengajuan, $vps);
            $this->newLine();
            $this->line('Data uji dibersihkan.');
        }

        $this->newLine();
        $this->info('Siklus hidup penuh berhasil.');

        return self::SUCCESS;
    }

    private function hitungYatim(RouterOsClient $router, int $idAkun): int
    {
        $tanda  = config('routeros.comment_prefix') . ':akun:' . $idAkun;
        $jumlah = 0;

        foreach (['ip/firewall/filter', 'ip/firewall/address-list', 'ppp/secret'] as $path) {
            foreach ($router->daftar($path) as $objek) {
                if (($objek['comment'] ?? '') === $tanda) {
                    $jumlah++;
                }
            }
        }

        return $jumlah;
    }

    private function bersihkan(?AkunVpn $akun, Pengajuan $pengajuan, Vps $vps): void
    {
        if ($akun) {
            AkunVpn::withTrashed()->whereKey($akun->id)->forceDelete();
        }
        $pengajuan->delete();
        $vps->forceDelete();
    }

    private function tahap(string $judul): void
    {
        $this->newLine();
        $this->line("<fg=cyan;options=bold>{$judul}</>");
    }

    private function hasil(array $data): void
    {
        foreach ($data as $k => $v) {
            $this->line(sprintf('   %-18s %s', $k, $v));
        }
    }
}
