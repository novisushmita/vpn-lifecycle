<?php

namespace App\Services\Vpn;

use App\Models\AkunVpn;
use App\Models\SesiVpn;
use App\Services\RouterOs\RouterOsClient;
use Illuminate\Support\Carbon;

/**
 * Log sesi (CLAUDE.md keputusan #2, Tingkat 1).
 *
 * Membandingkan daftar sesi aktif di router dengan sesi yang masih terbuka di
 * basis data, lalu membuka atau menutup baris seperlunya. Hanya metadata sesi
 * yang dicatat — tidak ada isi komunikasi.
 */
class PencatatSesi
{
    public function __construct(private readonly RouterOsClient $router) {}

    /** @return array{dibuka:int, ditutup:int, diperbarui:int} */
    public function sinkronkan(): array
    {
        $aktifDiRouter = [];
        foreach ($this->router->daftar('ppp/active') as $sesi) {
            if (isset($sesi['name'])) {
                $aktifDiRouter[$sesi['name']] = $sesi;
            }
        }

        // Penghitung byte TIDAK ada di /ppp active — di sana hanya ada
        // limit-bytes-* yang artinya batas kuota, bukan pemakaian. Angka
        // sesungguhnya hidup di interface dinamis yang dibuat RouterOS untuk
        // tiap sesi, bernama <l2tp-USERNAME>.
        $byte = $this->petaByte();

        $hasil = ['dibuka' => 0, 'ditutup' => 0, 'diperbarui' => 0];

        $akunPerUsername = AkunVpn::adaDiRouter()->get()->keyBy('username');

        foreach ($akunPerUsername as $username => $akun) {
            $diRouter = $aktifDiRouter[$username] ?? null;
            $terbuka  = SesiVpn::where('akun_vpn_id', $akun->id)->where('aktif', true)->first();

            if ($diRouter && ! $terbuka) {
                $this->buka($akun, $diRouter, $byte[$username] ?? null);
                $hasil['dibuka']++;
            } elseif ($diRouter && $terbuka) {
                $this->perbarui($terbuka, $byte[$username] ?? null);
                $hasil['diperbarui']++;
            } elseif (! $diRouter && $terbuka) {
                $this->tutup($terbuka);
                $hasil['ditutup']++;
            }
        }

        // Sesi yang akunnya sudah tidak ada di basis data tetap perlu ditutup.
        $ditutupYatim = SesiVpn::where('aktif', true)
            ->whereNotIn('akun_vpn_id', $akunPerUsername->pluck('id'))
            ->get();

        foreach ($ditutupYatim as $sesi) {
            $this->tutup($sesi);
            $hasil['ditutup']++;
        }

        return $hasil;
    }

    /**
     * Penghitung byte per sesi, diindeks berdasarkan username.
     *
     * Arah dilihat dari sisi KLIEN, bukan router:
     *   bytes_in  (download klien) = tx-byte interface, yang dikirim router
     *   bytes_out (upload klien)   = rx-byte interface, yang diterima router
     *
     * @return array<string,array{in:int,out:int}>
     */
    private function petaByte(): array
    {
        $peta = [];

        foreach ($this->router->daftar('interface') as $i) {
            if ($baris = self::byteDariInterface($i)) {
                $peta[$baris['username']] = ['in' => $baris['in'], 'out' => $baris['out']];
            }
        }

        return $peta;
    }

    /**
     * Menguraikan satu baris /interface menjadi pemakaian byte satu sesi.
     * Mengembalikan null bila baris itu bukan interface sesi L2TP.
     *
     * @return array{username:string,in:int,out:int}|null
     */
    public static function byteDariInterface(array $i): ?array
    {
        if (($i['type'] ?? '') !== 'l2tp-in') {
            return null;
        }

        // Nama berbentuk <l2tp-USERNAME>; ambil username di dalamnya.
        if (! preg_match('/^<l2tp-(.+)>$/', (string) ($i['name'] ?? ''), $cocok)) {
            return null;
        }

        return [
            'username' => $cocok[1],
            'in'       => (int) ($i['tx-byte'] ?? 0),
            'out'      => (int) ($i['rx-byte'] ?? 0),
        ];
    }

    private function buka(AkunVpn $akun, array $diRouter, ?array $byte): void
    {
        SesiVpn::create([
            'akun_vpn_id' => $akun->id,
            // Salinan, bukan relasi: riwayat harus tetap benar bila username diedit.
            'username_snapshot' => $akun->username,
            'ip_vpn'            => $diRouter['address'] ?? $akun->ip_vpn,
            'ip_asal'           => $diRouter['caller-id'] ?? null,
            // Waktu mulai dihitung mundur dari uptime router, bukan waktu polling,
            // supaya jeda antar polling tidak menggeser durasi sesi.
            'mulai_pada'        => $this->mulaiDari($diRouter['uptime'] ?? null),
            'bytes_in'          => $byte['in'] ?? 0,
            'bytes_out'         => $byte['out'] ?? 0,
            'aktif'             => true,
        ]);
    }

    private function perbarui(SesiVpn $sesi, ?array $byte): void
    {
        if (! $byte) {
            return;
        }

        // Nilai terakhir sebelum sesi putus adalah yang tersimpan permanen:
        // interface dinamisnya ikut hilang bersama sesinya, sehingga angka
        // final hanya seakurat interval polling (30 detik).
        $sesi->update([
            'bytes_in'  => $byte['in'],
            'bytes_out' => $byte['out'],
        ]);
    }

    private function tutup(SesiVpn $sesi): void
    {
        $selesai = now();

        $sesi->update([
            'aktif'        => false,
            'selesai_pada' => $selesai,
            'durasi_detik' => max(0, $selesai->diffInSeconds($sesi->mulai_pada, true)),
        ]);
    }

    /** Uptime RouterOS berbentuk gabungan satuan, mis. "1h2m30s" atau "45s". */
    public function mulaiDari(?string $uptime): Carbon
    {
        if (! $uptime) {
            return now();
        }

        preg_match_all('/(\d+)([wdhms])/', $uptime, $cocok, PREG_SET_ORDER);

        $detik = 0;
        foreach ($cocok as [, $angka, $satuan]) {
            $detik += (int) $angka * match ($satuan) {
                'w' => 604800,
                'd' => 86400,
                'h' => 3600,
                'm' => 60,
                's' => 1,
            };
        }

        return now()->subSeconds($detik);
    }
}
