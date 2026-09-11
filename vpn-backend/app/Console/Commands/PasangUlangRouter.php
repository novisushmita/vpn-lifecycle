<?php

namespace App\Console\Commands;

use App\Enums\StatusAkun;
use App\Models\AkunVpn;
use App\Models\Drift;
use App\Models\PaketBandwidth;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use App\Services\Vpn\ProvisioningService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Membangun ulang seluruh objek akun di router dari basis data.
 *
 * Dipakai ketika router diganti atau dikembalikan ke keadaan kosong. Basis
 * data adalah pemegang kebenaran; router disamakan dengannya.
 *
 * Yang TIDAK dikerjakan perintah ini: fondasi router (sertifikat, service,
 * pengguna API, pool, PPP profile, aturan firewall dasar). Semua itu berada di
 * luar cakupan sistem sesuai Batasan Masalah #3 dan disiapkan chr-setup.rsc.
 */
class PasangUlangRouter extends Command
{
    private const PATH_SECRET = 'ppp/secret';
    private const PATH_ADDRLIST = 'ip/firewall/address-list';
    private const PATH_FILTER = 'ip/firewall/filter';

    protected $signature = 'vpn:pasang-ulang
                            {--dry-run : Tampilkan yang akan dikerjakan tanpa menyentuh router}
                            {--paksa : Lewati konfirmasi}';

    protected $description = 'Membangun ulang objek seluruh akun di router dari basis data';

    public function handle(): int
    {
        $kering = (bool) $this->option('dry-run');
        $router = RouterOsClient::dariConfig();

        // --- 1. Router harus dapat dihubungi ---
        try {
            $info = $router->cekKoneksi();
        } catch (RouterOsException $e) {
            $this->error('Router tidak dapat dihubungi.');
            $this->line('  ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tersambung ke {$info['identity']}, RouterOS {$info['versi']}.");

        // --- 2. Fondasi harus sudah ada ---
        if ($kurang = $this->fondasiKurang($router)) {
            $this->newLine();
            $this->error('Fondasi router belum lengkap:');
            foreach ($kurang as $k) {
                $this->line("  - {$k}");
            }
            $this->newLine();
            $this->comment('Jalankan chr-setup.rsc lebih dulu, lalu ulangi perintah ini.');

            return self::FAILURE;
        }

        // --- 3. Akun yang objeknya seharusnya ada di router ---
        $akun = AkunVpn::adaDiRouter()->with(['vps', 'paketBandwidth'])->orderBy('id')->get();

        if ($akun->isEmpty()) {
            $this->info('Tidak ada akun yang perlu dipasang ulang.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Username', 'Status', 'VPS', 'Cara'],
            $akun->map(fn ($a) => [
                $a->username,
                $a->status->label(),
                $a->vps->nama,
                $a->status === StatusAkun::GagalProvision ? 'provision ulang' : 'pasang objek',
            ])->all(),
        );

        if ($kering) {
            $this->comment('[dry-run] Tidak ada yang diubah.');

            return self::SUCCESS;
        }

        $this->warn(
            "Ikatan ke router lama pada {$akun->count()} akun akan dilepas, "
            . 'lalu seluruh objeknya dibuat ulang di router ini.'
        );

        if (! $this->option('paksa') && ! $this->confirm('Lanjutkan?', false)) {
            $this->line('Dibatalkan.');

            return self::SUCCESS;
        }

        // --- 4. Pasang ulang satu per satu ---
        $layanan = new ProvisioningService($router);
        $berhasil = 0;
        $gagal    = [];

        $bar = $this->output->createProgressBar($akun->count());
        $bar->start();

        foreach ($akun as $a) {
            try {
                // Bila router TIDAK diganti (mis. hanya dinyalakan ulang),
                // objek lama masih hidup dengan nama yang sama. Membuang .id
                // lebih dulu tanpa membersihkan objeknya membuat perbaiki()
                // mencoba membuat objek baru bernama sama -> RouterOS menolak
                // -> job gagal -> DB sudah telanjur menyimpan .id kosong,
                // padahal objek lama masih ada di router, tidak terlacak lagi.
                // Ini persis yatim_di_router yang harusnya dicegah perintah ini.
                //
                // Jadi objek lama dihapus (best-effort) SEBELUM .id dilepas.
                // Pada router yang benar-benar baru, penghapusan ini tidak
                // menemukan apa pun dan diam-diam dilewati.
                $this->hapusJikaAda($router, self::PATH_SECRET, $a->router_secret_id);
                $this->hapusJikaAda($router, self::PATH_ADDRLIST, $a->router_addresslist_id);
                $this->hapusJikaAda($router, self::PATH_FILTER, $a->router_firewall_id);

                $a->forceFill([
                    'router_secret_id'      => null,
                    'router_addresslist_id' => null,
                    'router_firewall_id'    => null,
                ])->save();

                // Akun yang gagal diproses perlu transisi status, bukan sekadar
                // perbaikan objek; perbaiki() sengaja tidak menyentuh status.
                $a->status === StatusAkun::GagalProvision
                    ? $layanan->provision($a->fresh())
                    : $layanan->perbaiki($a->fresh());

                $berhasil++;
            } catch (Throwable $e) {
                $gagal[] = [$a->username, mb_substr($e->getMessage(), 0, 70)];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // --- 5. Temuan lama tidak lagi berlaku ---
        $ditutup = Drift::terbuka()->update([
            'status'            => 'diselesaikan',
            'resolusi'          => 'push',
            'diselesaikan_pada' => now(),
        ]);

        $this->info("Selesai. Berhasil {$berhasil}, gagal " . count($gagal) . ", temuan lama ditutup {$ditutup}.");

        if ($gagal !== []) {
            $this->newLine();
            $this->table(['Username', 'Penyebab'], $gagal);
            $this->comment('Akun yang gagal tetap tercatat dan akan muncul sebagai temuan pada pemeriksaan berikutnya.');
        }

        $this->newLine();
        $this->comment('Verifikasi dengan: php artisan vpn:sinkron');

        return $gagal === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Menghapus objek bila id-nya ada; diam-diam dilewati bila sudah tidak ada. */
    private function hapusJikaAda(RouterOsClient $router, string $path, ?string $id): void
    {
        if (! $id) {
            return;
        }

        try {
            $router->hapus($path, $id);
        } catch (RouterOsException) {
            // Sudah tidak ada, atau id dari router lain — tidak masalah,
            // tujuan kita hanya memastikan namanya bebas dipakai lagi.
        }
    }

    /**
     * Objek fondasi yang harus sudah ada sebelum akun dapat dipasang.
     *
     * @return string[] keterangan yang kurang; kosong berarti siap
     */
    private function fondasiKurang(RouterOsClient $router): array
    {
        $kurang = [];

        $namaPool = (string) config('routeros.ip_pool');
        $adaPool  = collect($router->daftar('ip/pool'))->contains(fn ($p) => ($p['name'] ?? null) === $namaPool);

        if (! $adaPool) {
            $kurang[] = "IP pool '{$namaPool}' tidak ditemukan";
        }

        $profileDiRouter = collect($router->daftar('ppp/profile'))->pluck('name')->all();

        foreach (PaketBandwidth::where('aktif', true)->get() as $paket) {
            if (! in_array($paket->ppp_profile, $profileDiRouter, true)) {
                $kurang[] = "PPP profile '{$paket->ppp_profile}' untuk paket {$paket->nama} tidak ditemukan";
            }
        }

        // Aturan tolak default adalah tempat aturan izin tiap akun disisipkan.
        // Tanpa itu, aturan izin tidak punya acuan posisi dan isolasi tidak berlaku.
        $comment  = (string) config('routeros.aturan_tolak');
        $adaTolak = collect($router->daftar('ip/firewall/filter'))
            ->contains(fn ($f) => ($f['comment'] ?? null) === $comment);

        if (! $adaTolak) {
            $kurang[] = "Aturan firewall tolak default (comment: \"{$comment}\") tidak ditemukan";
        }

        return $kurang;
    }
}
