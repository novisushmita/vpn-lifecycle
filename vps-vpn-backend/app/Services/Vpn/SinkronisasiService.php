<?php

namespace App\Services\Vpn;

use App\Models\AkunVpn;
use App\Models\Drift;
use App\Models\OperasiRouter;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use RuntimeException;

/**
 * Deteksi dan rekonsiliasi drift — penjamin bahwa seluruh transisi siklus
 * hidup benar-benar konsisten antara basis data dan router.
 *
 * Pola desired state reconciliation: basis data adalah desired state, router
 * adalah actual state, selisihnya dicatat sebagai temuan untuk diputuskan
 * admin (push atau pull).
 */
class SinkronisasiService
{
    public function __construct(
        private readonly RouterOsClient $router,
        private readonly ProvisioningService $provisioning,
    ) {}

    /** @return array{diperiksa:int, temuan:int, ditutup:int} */
    public function periksa(): array
    {
        $mulai   = hrtime(true);
        $operasi = OperasiRouter::create([
            'jenis' => 'sinkron', 'status' => 'berjalan', 'dimulai_pada' => now(),
        ]);

        try {
            $secret    = $this->petakan('ppp/secret');
            $addrList  = $this->petakan('ip/firewall/address-list');
            $firewall  = $this->petakan('ip/firewall/filter');

            $temuan    = 0;
            $diperiksa = 0;
            $idTerlihat = [];

            foreach (AkunVpn::adaDiRouter()->with(['vps', 'paketBandwidth'])->get() as $akun) {
                $diperiksa++;
                $temuan += $this->periksaAkun($akun, $secret, $addrList, $firewall);
                $idTerlihat[] = $akun->id;
            }

            $temuan += $this->periksaYatim([$secret, $addrList, $firewall], $idTerlihat);

            // Temuan yang tidak muncul lagi berarti sudah teratasi.
            $ditutup = Drift::terbuka()
                ->where('terdeteksi_pada', '<', now()->subSeconds(5))
                ->whereNotIn('id', $this->idTemuanBaru)
                ->update(['status' => 'diselesaikan', 'diselesaikan_pada' => now()]);

            $operasi->update([
                'status' => 'sukses',
                'hasil'  => ['diperiksa' => $diperiksa, 'temuan' => $temuan, 'ditutup' => $ditutup],
                'selesai_pada' => now(),
                'durasi_ms' => (int) round((hrtime(true) - $mulai) / 1_000_000),
            ]);

            return ['diperiksa' => $diperiksa, 'temuan' => $temuan, 'ditutup' => $ditutup];
        } catch (\Throwable $e) {
            $operasi->update([
                'status' => 'gagal', 'pesan_error' => $e->getMessage(),
                'selesai_pada' => now(),
                'durasi_ms' => (int) round((hrtime(true) - $mulai) / 1_000_000),
            ]);

            throw $e;
        }
    }

    private array $idTemuanBaru = [];

    private function periksaAkun(AkunVpn $akun, array $secret, array $addrList, array $firewall): int
    {
        $n = 0;

        // --- ppp secret ---
        $s = $akun->router_secret_id ? ($secret[$akun->router_secret_id] ?? null) : null;

        if (! $s) {
            $n += $this->catat($akun, 'ppp_secret', null, 'hilang_di_router', $akun->username, null);
        } else {
            $harusEnabled = $akun->status->seharusnyaEnabledDiRouter();
            $kiniEnabled  = ($s['disabled'] ?? 'false') !== 'true';

            if ($harusEnabled !== $kiniEnabled) {
                $n += $this->catat($akun, 'ppp_secret', 'disabled', 'nilai_beda',
                    $harusEnabled ? 'false' : 'true', $s['disabled'] ?? 'false');
            }

            if (($s['profile'] ?? null) !== $akun->paketBandwidth->ppp_profile) {
                $n += $this->catat($akun, 'ppp_secret', 'profile', 'nilai_beda',
                    $akun->paketBandwidth->ppp_profile, $s['profile'] ?? null);
            }

            if (($s['remote-address'] ?? null) !== $akun->ip_vpn) {
                $n += $this->catat($akun, 'ppp_secret', 'remote-address', 'nilai_beda',
                    $akun->ip_vpn, $s['remote-address'] ?? null);
            }
        }

        // --- address-list: daftar tujuan yang boleh dijangkau ---
        $a = $akun->router_addresslist_id ? ($addrList[$akun->router_addresslist_id] ?? null) : null;

        if (! $a) {
            $n += $this->catat($akun, 'address_list', null, 'hilang_di_router', $akun->vps->alamat_ip, null);
        } elseif (($a['address'] ?? null) !== $akun->vps->alamat_ip) {
            $n += $this->catat($akun, 'address_list', 'address', 'nilai_beda',
                $akun->vps->alamat_ip, $a['address'] ?? null);
        }

        // --- aturan firewall ---
        $f = $akun->router_firewall_id ? ($firewall[$akun->router_firewall_id] ?? null) : null;

        if (! $f) {
            $n += $this->catat($akun, 'firewall_rule', null, 'hilang_di_router', 'accept ' . $akun->ip_vpn, null);
        } elseif (($f['src-address'] ?? null) !== $akun->ip_vpn) {
            $n += $this->catat($akun, 'firewall_rule', 'src-address', 'nilai_beda',
                $akun->ip_vpn, $f['src-address'] ?? null);
        }

        if ($n === 0) {
            $akun->forceFill(['disinkron_pada' => now()])->save();
        }

        return $n;
    }

    /**
     * Objek bertanda milik sistem yang tidak punya pasangan akun aktif.
     * Inilah sisa dari penghapusan yang gagal separuh jalan.
     */
    private function periksaYatim(array $peta, array $idAkunHidup): int
    {
        $prefix = config('routeros.comment_prefix') . ':akun:';
        $jenis  = ['ppp_secret', 'address_list', 'firewall_rule'];
        $n = 0;

        foreach ($peta as $i => $objekPerId) {
            foreach ($objekPerId as $id => $objek) {
                $comment = $objek['comment'] ?? '';

                if (! str_starts_with($comment, $prefix)) {
                    continue; // dibuat manual oleh admin, bukan urusan sistem
                }

                $idAkun = (int) substr($comment, strlen($prefix));

                if (in_array($idAkun, $idAkunHidup, true)) {
                    continue;
                }

                $n += $this->catat(
                    AkunVpn::withTrashed()->find($idAkun),
                    $jenis[$i],
                    null,
                    'yatim_di_router',
                    null,
                    $id . ' (' . $comment . ')',
                );
            }
        }

        return $n;
    }

    private function catat(?AkunVpn $akun, string $jenisObjek, ?string $atribut, string $jenisDrift, ?string $db, ?string $router): int
    {
        $drift = Drift::firstOrNew([
            'akun_vpn_id' => $akun?->id,
            'jenis_objek' => $jenisObjek,
            'atribut'     => $atribut,
            'jenis_drift' => $jenisDrift,
            'status'      => 'terbuka',
        ]);

        $drift->fill([
            'vps_id'          => $akun?->vps_id,
            'nilai_db'        => $db,
            'nilai_router'    => $router,
            'terdeteksi_pada' => now(),
        ])->save();

        $this->idTemuanBaru[] = $drift->id;

        return 1;
    }

    /* ------------------------------------------------------------ REKONSILIASI */

    /**
     * push = router mengikuti basis data (memasang ulang / memperbaiki nilai)
     * pull = basis data mengikuti router (mengakui perubahan manual admin)
     */
    public function selesaikan(Drift $drift, string $resolusi, ?int $olehUserId = null): Drift
    {
        $akun = $drift->akunVpn;

        if (! $akun) {
            throw new RuntimeException('Temuan ini tidak terkait akun yang masih ada.');
        }

        if ($resolusi === 'push') {
            $this->push($drift, $akun, $olehUserId);
        } elseif ($resolusi === 'pull') {
            $this->pull($drift, $akun);
        } else {
            throw new RuntimeException("Resolusi tidak dikenal: {$resolusi}");
        }

        $drift->update([
            'status'            => 'diselesaikan',
            'resolusi'          => $resolusi,
            'diselesaikan_pada' => now(),
            'diselesaikan_oleh' => $olehUserId,
        ]);

        return $drift;
    }

    private function push(Drift $drift, AkunVpn $akun, ?int $olehUserId): void
    {
        if ($drift->jenis_drift === 'yatim_di_router') {
            // Objek yatim: buang dari router.
            [$path, $kolom] = $this->pathDan($drift->jenis_objek);
            $id = strtok((string) $drift->nilai_router, ' ');

            try {
                $this->router->hapus($path, $id);
            } catch (RouterOsException $e) {
                if ($e->statusHttp !== 404) {
                    throw $e;
                }
            }

            return;
        }

        // Untuk selisih nilai maupun objek hilang: kembalikan objek router
        // ke nilai menurut basis data. Status akun tidak disentuh.
        $this->provisioning->perbaiki($akun, $olehUserId);
    }

    private function pull(Drift $drift, AkunVpn $akun): void
    {
        if ($drift->jenis_drift !== 'nilai_beda') {
            throw new RuntimeException(
                'Pull hanya berlaku untuk selisih nilai. Objek yang hilang atau yatim harus diselesaikan dengan push.'
            );
        }

        match ([$drift->jenis_objek, $drift->atribut]) {
            ['ppp_secret', 'remote-address'] => $akun->forceFill(['ip_vpn' => $drift->nilai_router])->save(),
            ['ppp_secret', 'disabled']       => $akun->forceFill([
                'status' => $drift->nilai_router === 'true'
                    ? \App\Enums\StatusAkun::Dinonaktifkan
                    : \App\Enums\StatusAkun::Aktif,
            ])->save(),
            default => throw new RuntimeException(
                "Atribut {$drift->jenis_objek}.{$drift->atribut} tidak dapat di-pull; "
                . 'nilainya berasal dari data master (paket bandwidth atau VPS).'
            ),
        };

        $akun->forceFill(['disinkron_pada' => now()])->save();
    }

    /** @return array{0:string,1:string} */
    private function pathDan(string $jenisObjek): array
    {
        return match ($jenisObjek) {
            'ppp_secret'    => ['ppp/secret', 'router_secret_id'],
            'address_list'  => ['ip/firewall/address-list', 'router_addresslist_id'],
            'firewall_rule' => ['ip/firewall/filter', 'router_firewall_id'],
        };
    }

    /** @return array<string,array<string,mixed>> objek diindeks berdasarkan .id */
    private function petakan(string $path): array
    {
        $peta = [];
        foreach ($this->router->daftar($path) as $objek) {
            if (isset($objek['.id'])) {
                $peta[$objek['.id']] = $objek;
            }
        }

        return $peta;
    }
}
