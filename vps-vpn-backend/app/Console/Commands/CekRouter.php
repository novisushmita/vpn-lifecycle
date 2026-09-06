<?php

namespace App\Console\Commands;

use App\Models\PaketBandwidth;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use Illuminate\Console\Command;

/**
 * Preflight: memastikan router siap dipakai sistem SEBELUM ada akun dibuat.
 * Yang diperiksa adalah prasyarat yang berada DI LUAR lingkup sistem
 * (CLAUDE.md batasan #3) — sistem tidak membuatnya, hanya memakainya.
 */
class CekRouter extends Command
{
    protected $signature = 'router:cek {--ping= : Uji ping ke satu alamat IP dari sisi router}';

    protected $description = 'Memeriksa koneksi dan kesiapan MikroTik RouterOS';

    public function handle(): int
    {
        $klien = RouterOsClient::dariConfig();
        $this->line('Menghubungi <options=bold>' . config('routeros.base_url') . '</> sebagai <options=bold>' . config('routeros.user') . '</>');
        $this->newLine();

        try {
            $info = $klien->cekKoneksi();
        } catch (RouterOsException $e) {
            $this->error('GAGAL TERSAMBUNG');
            $this->line($e->getMessage());
            $this->newLine();
            $this->comment('Periksa: CHR menyala, adapter host-only aktif, service www-ssl enable, dan user API sudah dibuat.');

            return self::FAILURE;
        }

        $this->info('TERSAMBUNG');
        $this->table(['Atribut', 'Nilai'], [
            ['Identity', $info['identity']],
            ['Versi RouterOS', $info['versi']],
            ['Board', $info['board']],
            ['Arsitektur', $info['arsitektur']],
            ['Uptime', $info['uptime']],
        ]);

        if (! str_starts_with((string) $info['versi'], '7')) {
            $this->warn('Versi RouterOS bukan 7.x — REST API mungkin tidak tersedia.');
        }

        $baris  = [];
        $gagal  = 0;

        // 1. IP pool untuk alamat klien VPN
        $namaPool = config('routeros.ip_pool');
        [$ok, $ket] = $this->periksa(
            fn () => $this->adaDenganNama($klien, 'ip/pool', $namaPool),
            "pool '{$namaPool}' ditemukan",
            "pool '{$namaPool}' TIDAK ADA — klien VPN tidak akan dapat alamat",
        );
        $baris[] = ['IP pool klien VPN', $ok ? 'OK' : 'BELUM', $ket];
        $gagal += $ok ? 0 : 1;

        // 2. PPP profile untuk tiap paket bandwidth
        foreach (PaketBandwidth::where('aktif', true)->get() as $paket) {
            [$ok, $ket] = $this->periksa(
                fn () => $this->adaDenganNama($klien, 'ppp/profile', $paket->ppp_profile),
                "profile '{$paket->ppp_profile}' ditemukan",
                "profile '{$paket->ppp_profile}' TIDAK ADA",
            );
            $baris[] = ["PPP profile · {$paket->nama} ({$paket->rateLimit()})", $ok ? 'OK' : 'BELUM', $ket];
            $gagal += $ok ? 0 : 1;
        }

        // 3. L2TP server aktif
        [$ok, $ket] = $this->periksa(function () use ($klien) {
            $l2tp = $klien->daftar('interface/l2tp-server/server');
            $data = $l2tp[0] ?? $l2tp;

            return ($data['enabled'] ?? 'false') === 'true'
                ? [true, 'L2TP server enabled']
                : [false, 'L2TP server masih disabled'];
        }, null, null);
        $baris[] = ['L2TP server', $ok ? 'OK' : 'BELUM', $ket];
        $gagal += $ok ? 0 : 1;

        // 4. Menu yang akan ditulis sistem harus bisa dibaca
        foreach ([
            'ppp/secret'                => 'Menu ppp/secret',
            'ip/firewall/address-list'  => 'Menu address-list',
            'ip/firewall/filter'        => 'Menu firewall filter',
            'ppp/active'                => 'Menu ppp/active (log sesi)',
        ] as $path => $label) {
            [$ok, $ket] = $this->periksa(function () use ($klien, $path) {
                $klien->daftar($path);

                return [true, 'dapat dibaca'];
            }, null, null);
            $baris[] = [$label, $ok ? 'OK' : 'GAGAL', $ket];
            $gagal += $ok ? 0 : 1;
        }

        $this->newLine();
        $this->table(['Prasyarat', 'Status', 'Keterangan'], $baris);

        if ($alamat = $this->option('ping')) {
            $this->newLine();
            $this->line("Ping <options=bold>{$alamat}</> dari sisi router:");
            try {
                $p = $klien->ping($alamat);
                $this->line(sprintf(
                    '  status=%s  rtt=%s  loss=%d%%',
                    $p['status'],
                    $p['rtt_avg_ms'] === null ? '-' : $p['rtt_avg_ms'] . ' ms',
                    $p['packet_loss'],
                ));
            } catch (RouterOsException $e) {
                $this->error('  ' . $e->getMessage());
            }
        }

        $this->newLine();
        if ($gagal > 0) {
            $this->warn("{$gagal} prasyarat belum siap. Jalankan skrip konfigurasi CHR di SETUP.md bagian 12.");

            return self::FAILURE;
        }

        $this->info('Router siap dipakai sistem.');

        return self::SUCCESS;
    }

    /** @return array{0:bool,1:string} */
    private function periksa(callable $uji, ?string $pesanOk, ?string $pesanGagal): array
    {
        try {
            $hasil = $uji();

            if (is_array($hasil)) {
                return [$hasil[0], $hasil[1]];
            }

            return $hasil ? [true, $pesanOk ?? 'OK'] : [false, $pesanGagal ?? 'tidak ditemukan'];
        } catch (RouterOsException $e) {
            return [false, $e->getMessage()];
        }
    }

    private function adaDenganNama(RouterOsClient $klien, string $path, string $nama): bool
    {
        foreach ($klien->daftar($path) as $item) {
            if (($item['name'] ?? null) === $nama) {
                return true;
            }
        }

        return false;
    }
}
