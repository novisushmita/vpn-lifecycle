<?php

namespace App\Services\Vpn;

use App\Enums\StatusAkun;
use App\Models\AkunVpn;
use App\Models\OperasiRouter;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Seluruh operasi siklus hidup akun terhadap router.
 *
 * Tiga aturan yang berlaku untuk SEMUA method publik di kelas ini:
 *   1. Idempoten — aman dijalankan ulang, memeriksa dulu sebelum membuat.
 *   2. Bila perambatan ke router gagal, status di basis data TIDAK ikut
 *      berubah. Basis data tidak boleh mengklaim sesuatu yang tidak benar.
 *   3. Setiap operasi tercatat di tabel operasi_router lengkap dengan durasi.
 */
class ProvisioningService
{
    private const PATH_SECRET   = 'ppp/secret';
    private const PATH_ADDRLIST = 'ip/firewall/address-list';
    private const PATH_FILTER   = 'ip/firewall/filter';
    private const PATH_ACTIVE   = 'ppp/active';

    public function __construct(private readonly RouterOsClient $router) {}

    /** Baris operasi yang sudah dibuat pemanggil; dipakai sekali lalu dilepas. */
    private ?OperasiRouter $operasiBerjalan = null;

    /**
     * Memakai baris operasi yang sudah ada, alih-alih membuat yang baru.
     * Dipakai job antrean agar penanda yang dipantau antarmuka sejak awal
     * adalah baris yang sama dengan yang dicatat di akhir.
     */
    public function pakaiOperasi(OperasiRouter $operasi): static
    {
        $this->operasiBerjalan = $operasi;

        return $this;
    }

    /* ---------------------------------------------------------------- CREATE */

    /**
     * Transaksi tiga langkah: ppp secret -> address-list -> firewall filter.
     * Bila langkah mana pun gagal, langkah yang sudah terlanjur dibatalkan.
     */
    public function provision(AkunVpn $akun, ?int $olehUserId = null): AkunVpn
    {
        $akun->loadMissing(['vps', 'paketBandwidth']);

        $this->pastikanTransisi($akun, StatusAkun::Provisioning);
        $akun->forceFill(['status' => StatusAkun::Provisioning, 'pesan_error' => null])->save();

        return $this->catat('provision', $akun, $olehUserId, [
            'username' => $akun->username,
            'ip_vpn'   => $akun->ip_vpn,
            'vps'      => $akun->vps->alamat_ip,
            'profile'  => $akun->paketBandwidth->ppp_profile,
        ], function () use ($akun) {
            $dibuat = [];

            try {
                // 1. Kredensial VPN
                if (! $akun->router_secret_id || ! $this->objekAda(self::PATH_SECRET, $akun->router_secret_id)) {
                    $akun->router_secret_id = $this->idDari($this->router->buat(self::PATH_SECRET, [
                        'name'           => $akun->username,
                        'password'       => $akun->password,
                        'service'        => 'l2tp',
                        'profile'        => $akun->paketBandwidth->ppp_profile,
                        'remote-address' => $akun->ip_vpn,
                        'comment'        => $this->tanda($akun),
                    ]));
                    $dibuat[] = [self::PATH_SECRET, $akun->router_secret_id];
                }

                // 2. Daftar tujuan yang boleh dijangkau akun ini
                if (! $akun->router_addresslist_id || ! $this->objekAda(self::PATH_ADDRLIST, $akun->router_addresslist_id)) {
                    $akun->router_addresslist_id = $this->idDari($this->router->buat(self::PATH_ADDRLIST, [
                        'list'    => $this->namaDaftar($akun),
                        'address' => $akun->vps->alamat_ip,
                        'comment' => $this->tanda($akun),
                    ]));
                    $dibuat[] = [self::PATH_ADDRLIST, $akun->router_addresslist_id];
                }

                // 3. Aturan izin, disisipkan TEPAT DI ATAS aturan tolak-default
                if (! $akun->router_firewall_id || ! $this->objekAda(self::PATH_FILTER, $akun->router_firewall_id)) {
                    $akun->router_firewall_id = $this->idDari($this->router->buat(self::PATH_FILTER, [
                        'chain'             => 'forward',
                        'src-address'       => $akun->ip_vpn,
                        'dst-address-list'  => $this->namaDaftar($akun),
                        'action'            => 'accept',
                        'place-before'      => $this->idAturanTolak(),
                        'comment'           => $this->tanda($akun),
                    ]));
                    $dibuat[] = [self::PATH_FILTER, $akun->router_firewall_id];
                }
            } catch (Throwable $e) {
                $this->batalkan($dibuat);
                $akun->forceFill([
                    'status'      => StatusAkun::GagalProvision,
                    'pesan_error' => $e->getMessage(),
                ])->save();

                throw $e;
            }

            $akun->forceFill([
                'status'         => StatusAkun::Aktif,
                'disinkron_pada' => now(),
                'pesan_error'    => null,
            ])->save();

            return $akun->refresh();
        });
    }

    /**
     * Memaksa objek di router kembali sesuai basis data, TANPA mengubah status
     * akun. Dipakai rekonsiliasi drift arah "push".
     *
     * Berbeda dari provision(): akun yang sudah aktif tidak boleh dipaksa
     * kembali ke status provisioning hanya untuk memperbaiki satu atribut.
     */
    public function perbaiki(AkunVpn $akun, ?int $olehUserId = null): AkunVpn
    {
        $akun->loadMissing(['vps', 'paketBandwidth']);

        return $this->catat('sinkron', $akun, $olehUserId, [
            'username' => $akun->username,
        ], function () use ($akun) {
            $dipasangUlang = $this->pastikanObjekLengkap($akun);

            if (! $akun->router_secret_id || ! $this->objekAda(self::PATH_SECRET, $akun->router_secret_id)) {
                // disabled WAJIB disertakan di sini juga: RouterOS membuat
                // secret baru dengan disabled=no secara default, apa pun
                // status akun sebenarnya. Cabang ini dilalui saat objek lama
                // sudah tidak ada -- termasuk saat vpn:pasang-ulang membangun
                // ulang dari nol -- sehingga akun yang seharusnya nonaktif
                // bisa lolos ke router dalam keadaan aktif tanpa terdeteksi
                // (secret-nya ADA dan pemeriksaan drift hanya membandingkan
                // nilai, yang kebetulan baru pertama kali ditulis di sini).
                $akun->router_secret_id = $this->idDari($this->router->buat(self::PATH_SECRET, [
                    'name'           => $akun->username,
                    'password'       => $akun->password,
                    'service'        => 'l2tp',
                    'profile'        => $akun->paketBandwidth->ppp_profile,
                    'remote-address' => $akun->ip_vpn,
                    'disabled'       => $akun->status->seharusnyaEnabledDiRouter() ? 'false' : 'true',
                    'comment'        => $this->tanda($akun),
                ]));
                $dipasangUlang[] = 'ppp-secret';
            } else {
                // Kembalikan seluruh atribut yang dikelola sistem ke nilai
                // menurut basis data, apa pun yang diubah manual di router.
                $this->router->ubah(self::PATH_SECRET, $akun->router_secret_id, [
                    'profile'        => $akun->paketBandwidth->ppp_profile,
                    'remote-address' => $akun->ip_vpn,
                    'disabled'       => $akun->status->seharusnyaEnabledDiRouter() ? 'false' : 'true',
                ]);
            }

            if ($akun->router_addresslist_id) {
                $this->router->ubah(self::PATH_ADDRLIST, $akun->router_addresslist_id, [
                    'address' => $akun->vps->alamat_ip,
                ]);
            }

            $akun->forceFill(['disinkron_pada' => now()])->save();

            return ['dipasang_ulang' => $dipasangUlang];
        });
    }

    /* --------------------------------------------------------------- DISABLE */

    public function nonaktifkan(AkunVpn $akun, ?int $olehUserId = null): AkunVpn
    {
        $this->pastikanTransisi($akun, StatusAkun::Dinonaktifkan);

        return $this->catat('disable', $akun, $olehUserId, ['username' => $akun->username], function () use ($akun) {
            if ($akun->router_secret_id) {
                $this->router->ubah(self::PATH_SECRET, $akun->router_secret_id, ['disabled' => 'true']);
            }

            // WAJIB. disabled=yes hanya mencegah login BERIKUTNYA; sesi yang
            // sedang berjalan tetap hidup sampai pengguna memutus sendiri.
            $diputus = $this->putusSesi($akun);

            $akun->forceFill([
                'status'         => StatusAkun::Dinonaktifkan,
                'disinkron_pada' => now(),
            ])->save();

            return ['sesi_diputus' => $diputus];
        });
    }

    /* ---------------------------------------------------------------- ENABLE */

    public function aktifkan(AkunVpn $akun, ?int $olehUserId = null): AkunVpn
    {
        $akun->loadMissing('vps');
        $this->pastikanTransisi($akun, StatusAkun::Aktif);

        if ($akun->selesai_pada->isPast()) {
            throw new RuntimeException(
                'Akun sudah melewati tanggal selesai. Gunakan alur perpanjangan, bukan aktifkan.'
            );
        }

        return $this->catat('enable', $akun, $olehUserId, ['username' => $akun->username], function () use ($akun) {
            // Aturan firewall bisa hilang bila router pernah reboot tanpa
            // konfigurasi tersimpan, atau dihapus manual lewat Winbox.
            $dipasangUlang = $this->pastikanObjekLengkap($akun);

            $this->router->ubah(self::PATH_SECRET, $akun->router_secret_id, ['disabled' => 'false']);

            $akun->forceFill([
                'status'         => StatusAkun::Aktif,
                'disinkron_pada' => now(),
            ])->save();

            return ['objek_dipasang_ulang' => $dipasangUlang];
        });
    }

    /* ---------------------------------------------------------------- DELETE */

    /**
     * Kebalikan urutan provisioning: dari luar ke dalam.
     * Objek yang gagal dihapus dilaporkan supaya bisa ditandai sebagai yatim.
     */
    public function hapus(AkunVpn $akun, string $alasan = 'admin', ?int $olehUserId = null): AkunVpn
    {
        $this->pastikanTransisi($akun, StatusAkun::Dihapus);

        return $this->catat('hapus', $akun, $olehUserId, [
            'username' => $akun->username,
            'alasan'   => $alasan,
        ], function () use ($akun, $alasan) {
            $gagal = [];

            $this->putusSesi($akun);

            foreach ([
                'router_firewall_id'    => self::PATH_FILTER,
                'router_addresslist_id' => self::PATH_ADDRLIST,
                'router_secret_id'      => self::PATH_SECRET,
            ] as $kolom => $path) {
                if (! $akun->{$kolom}) {
                    continue;
                }

                try {
                    $this->router->hapus($path, $akun->{$kolom});
                    $akun->{$kolom} = null;
                } catch (RouterOsException $e) {
                    // 404 berarti memang sudah tidak ada — itu hasil yang diinginkan.
                    if ($e->statusHttp === 404) {
                        $akun->{$kolom} = null;

                        continue;
                    }
                    $gagal[] = $path;
                }
            }

            if ($gagal !== []) {
                throw new RouterOsException(
                    'Sebagian objek gagal dihapus dari router: ' . implode(', ', $gagal)
                );
            }

            $akun->forceFill([
                'status'             => StatusAkun::Dihapus,
                'alasan_penghapusan' => $alasan,
                'disinkron_pada'     => now(),
            ])->save();

            $akun->delete(); // soft delete: riwayat sesi & audit tetap utuh

            return ['objek_dihapus' => 3];
        });
    }

    /* ------------------------------------------------------------ KEDALUWARSA */

    /** Dipanggil scheduler saat akun melewati tanggal selesai. */
    public function kedaluwarsakan(AkunVpn $akun): AkunVpn
    {
        $this->pastikanTransisi($akun, StatusAkun::Kedaluwarsa);

        return $this->catat('expire', $akun, null, ['username' => $akun->username], function () use ($akun) {
            if ($akun->router_secret_id) {
                $this->router->ubah(self::PATH_SECRET, $akun->router_secret_id, ['disabled' => 'true']);
            }

            $diputus = $this->putusSesi($akun);

            $akun->forceFill([
                'status'         => StatusAkun::Kedaluwarsa,
                'disinkron_pada' => now(),
            ])->save();

            return ['sesi_diputus' => $diputus];
        });
    }

    /* ----------------------------------------------------------- PERPANJANGAN */

    /**
     * Memperpanjang masa akses. Bila akun sudah nonaktif di router karena
     * kedaluwarsa, ia dihidupkan kembali di sini — inilah yang menutup
     * siklus hidup supaya fase kedaluwarsa tidak menjadi jalan buntu.
     */
    public function perpanjang(AkunVpn $akun, string $selesaiBaru, ?int $olehUserId = null): AkunVpn
    {
        $akun->loadMissing('vps');

        if ($selesaiBaru <= $akun->selesai_pada->toDateString()) {
            throw new RuntimeException('Tanggal perpanjangan harus setelah tanggal selesai saat ini.');
        }

        $perluHidupkan = in_array($akun->status, [
            StatusAkun::Kedaluwarsa, StatusAkun::AkanKedaluwarsa,
        ], true);

        if ($perluHidupkan) {
            $this->pastikanTransisi($akun, StatusAkun::Aktif);
        }

        return $this->catat('extend', $akun, $olehUserId, [
            'selesai_lama' => $akun->selesai_pada->toDateString(),
            'selesai_baru' => $selesaiBaru,
        ], function () use ($akun, $selesaiBaru, $perluHidupkan) {
            $dipasangUlang = [];

            if ($perluHidupkan) {
                $dipasangUlang = $this->pastikanObjekLengkap($akun);
                $this->router->ubah(self::PATH_SECRET, $akun->router_secret_id, ['disabled' => 'false']);
            }

            $akun->forceFill([
                'selesai_pada'               => $selesaiBaru,
                'status'                     => $perluHidupkan ? StatusAkun::Aktif : $akun->status,
                // Direset supaya peringatan H-3 dikirim lagi menjelang tanggal baru.
                'peringatan_h3_dikirim_pada' => null,
                'disinkron_pada'             => now(),
            ])->save();

            return ['objek_dipasang_ulang' => $dipasangUlang, 'dihidupkan' => $perluHidupkan];
        });
    }

    /* ----------------------------------------------------------------- SESI */

    /** Memutus seluruh sesi aktif akun. Mengembalikan jumlah sesi yang diputus. */
    public function putusSesi(AkunVpn $akun): int
    {
        $jumlah = 0;

        foreach ($this->router->daftar(self::PATH_ACTIVE) as $sesi) {
            if (($sesi['name'] ?? null) !== $akun->username) {
                continue;
            }

            try {
                $this->router->hapus(self::PATH_ACTIVE, $sesi['.id']);
                $jumlah++;
            } catch (RouterOsException) {
                // sesi keburu putus sendiri
            }
        }

        return $jumlah;
    }

    /* -------------------------------------------------------------- INTERNAL */

    /** Memasang ulang objek yang hilang di router. Mengembalikan yang dipasang ulang. */
    private function pastikanObjekLengkap(AkunVpn $akun): array
    {
        $dipasang = [];

        if (! $akun->router_addresslist_id || ! $this->objekAda(self::PATH_ADDRLIST, $akun->router_addresslist_id)) {
            $akun->router_addresslist_id = $this->idDari($this->router->buat(self::PATH_ADDRLIST, [
                'list'    => $this->namaDaftar($akun),
                'address' => $akun->vps->alamat_ip,
                'comment' => $this->tanda($akun),
            ]));
            $dipasang[] = 'address-list';
        }

        if (! $akun->router_firewall_id || ! $this->objekAda(self::PATH_FILTER, $akun->router_firewall_id)) {
            $akun->router_firewall_id = $this->idDari($this->router->buat(self::PATH_FILTER, [
                'chain'            => 'forward',
                'src-address'      => $akun->ip_vpn,
                'dst-address-list' => $this->namaDaftar($akun),
                'action'           => 'accept',
                'place-before'     => $this->idAturanTolak(),
                'comment'          => $this->tanda($akun),
            ]));
            $dipasang[] = 'firewall-filter';
        }

        return $dipasang;
    }

    private function batalkan(array $dibuat): void
    {
        foreach (array_reverse($dibuat) as [$path, $id]) {
            try {
                $this->router->hapus($path, $id);
            } catch (Throwable) {
                // Sisa yang tidak terhapus akan tertangkap deteksi drift
                // sebagai objek yatim.
            }
        }
    }

    private function objekAda(string $path, string $id): bool
    {
        try {
            $this->router->ambil($path, $id);

            return true;
        } catch (RouterOsException) {
            return false;
        }
    }

    private function idAturanTolak(): string
    {
        $comment = (string) config('routeros.aturan_tolak');

        foreach ($this->router->daftar(self::PATH_FILTER) as $aturan) {
            if (($aturan['comment'] ?? null) === $comment) {
                return $aturan['.id'];
            }
        }

        throw new RuntimeException(
            "Aturan firewall tolak-default (comment: \"{$comment}\") tidak ditemukan di router. "
            . 'Jalankan chr-setup.rsc lebih dulu — tanpa aturan ini isolasi antar VPS tidak berlaku.'
        );
    }

    private function idDari(array $balasan): string
    {
        $id = $balasan['ret'] ?? $balasan['.id'] ?? null;

        if (! $id) {
            throw new RouterOsException('RouterOS tidak mengembalikan .id objek yang dibuat.');
        }

        return (string) $id;
    }

    private function tanda(AkunVpn $akun): string
    {
        return config('routeros.comment_prefix') . ':akun:' . $akun->id;
    }

    private function namaDaftar(AkunVpn $akun): string
    {
        return config('routeros.comment_prefix') . '-akun-' . $akun->id;
    }

    /** Mencatat operasi ke buku besar sekaligus mengukur durasinya (data Bab 4). */
    private function catat(string $jenis, AkunVpn $akun, ?int $olehUserId, array $payload, Closure $aksi): AkunVpn
    {
        // Bila controller sudah membuat baris penanda saat mengantrekan
        // pekerjaan, baris itu yang dipakai. Tanpa ini akan ada dua baris
        // untuk satu operasi: satu dari controller, satu dari sini.
        $operasi = $this->operasiBerjalan ?? OperasiRouter::make();
        $this->operasiBerjalan = null;

        $operasi->forceFill([
            'jenis'        => $jenis,
            'akun_vpn_id'  => $akun->id,
            'vps_id'       => $akun->vps_id,
            'status'       => 'berjalan',
            'payload'      => array_merge($operasi->payload ?? [], $payload),
            'dimulai_pada' => now(),
            'dipicu_oleh'  => $operasi->dipicu_oleh ?? $olehUserId,
        ])->save();

        $mulai = hrtime(true);

        try {
            $hasil = $aksi();

            $operasi->update([
                'status'       => 'sukses',
                'hasil'        => is_array($hasil) ? $hasil : null,
                'selesai_pada' => now(),
                'durasi_ms'    => $this->msSejak($mulai),
            ]);
        } catch (Throwable $e) {
            $operasi->update([
                'status'       => 'gagal',
                'pesan_error'  => $e->getMessage(),
                'selesai_pada' => now(),
                'durasi_ms'    => $this->msSejak($mulai),
                'percobaan'    => $operasi->percobaan + 1,
            ]);

            throw $e;
        }

        return $akun->refresh();
    }

    private function msSejak(int $mulaiHrtime): int
    {
        return (int) round((hrtime(true) - $mulaiHrtime) / 1_000_000);
    }

    private function pastikanTransisi(AkunVpn $akun, StatusAkun $tujuan): void
    {
        if (! $akun->status->bisaPindahKe($tujuan)) {
            throw new RuntimeException(
                "Transisi tidak sah: {$akun->status->value} -> {$tujuan->value} untuk akun {$akun->username}."
            );
        }
    }
}
