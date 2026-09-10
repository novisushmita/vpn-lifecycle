<?php

namespace App\Services\Vpn;

use App\Enums\StatusAkun;
use App\Models\AkunVpn;
use App\Models\AuditLog;
use App\Models\OperasiRouter;
use App\Models\PaketBandwidth;
use App\Models\Vps;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EditAkunService
{
    public function __construct(private readonly RouterOsClient $router) {}

    public static function boleh(AkunVpn $akun): bool
    {
        return in_array($akun->status, [StatusAkun::Aktif, StatusAkun::AkanKedaluwarsa, StatusAkun::Dinonaktifkan, StatusAkun::Kedaluwarsa], true);
    }

    public function jalankan(int $id, array $data): void
    {
        $operasi = OperasiRouter::findOrFail($id);
        if (in_array($operasi->status, ['sukses', 'gagal'], true)) {
            return;
        }
        $mulai = hrtime(true);
        $operasi->update(['status' => 'berjalan', 'dimulai_pada' => now(), 'percobaan' => 1]);
        $ubah = [];
        try {
            DB::transaction(function () use ($operasi, $data, &$ubah, $mulai) {
                $akun = AkunVpn::whereKey($operasi->akun_vpn_id)->lockForUpdate()->firstOrFail();
                if (! self::boleh($akun)) {
                    throw new RuntimeException('Status akun tidak dapat diedit.');
                }
                if ($akun->updated_at->toISOString() !== ($operasi->payload['versi'] ?? null)) {
                    throw new RuntimeException('Akun berubah sejak edit diajukan. Muat ulang dan ajukan kembali.');
                }
                $vps = Vps::publik()->find($data['vps_id']);
                $paket = PaketBandwidth::where('aktif', true)->find($data['paket_bandwidth_id']);
                if (! $vps || ! $paket) {
                    throw new RuntimeException('VPS atau paket pilihan sudah tidak tersedia.');
                }
                if (AkunVpn::withTrashed()->where('username', $data['username'])->whereKeyNot($akun->id)->exists()) {
                    throw new RuntimeException('Username sudah digunakan akun lain.');
                }
                $lama = $akun->only(['username', 'vps_id', 'paket_bandwidth_id']);
                $tanda = config('routeros.comment_prefix').':akun:'.$akun->id;
                $list = config('routeros.comment_prefix').'-akun-'.$akun->id;
                $secretBaru = ['name' => $data['username'], 'profile' => $paket->ppp_profile];
                if (! empty($data['password'])) {
                    $secretBaru['password'] = $data['password'];
                }
                $rencana = [
                    ['ppp/secret', $akun->router_secret_id, $secretBaru],
                    ['ip/firewall/address-list', $akun->router_addresslist_id, ['list' => $list, 'address' => $vps->alamat_ip]],
                    ['ip/firewall/filter', $akun->router_firewall_id, ['chain' => 'forward', 'action' => 'accept', 'src-address' => $akun->ip_vpn, 'dst-address-list' => $list]],
                ];
                // Baca seluruh snapshot sebelum mengubah objek pertama.
                foreach ($rencana as &$langkah) {
                    [$path, $objekId, $nilai] = $langkah;
                    if (! $objekId) {
                        throw new RuntimeException('Objek router belum lengkap. Periksa sinkronisasi dahulu.');
                    }
                    $snapshot = $this->router->ambil($path, $objekId);
                    if (($snapshot['comment'] ?? null) !== $tanda) {
                        throw new RuntimeException('Kepemilikan objek router tidak sesuai. Periksa sinkronisasi dahulu.');
                    }
                    $sebelum = [];
                    foreach (array_keys($nilai) as $key) {
                        if ($path === 'ppp/secret' && $key === 'password') {
                            // CHR menyamarkan password API; nilai pemulihan berasal dari DB terenkripsi.
                            $sebelum[$key] = $akun->password;

                            continue;
                        }
                        if (! array_key_exists($key, $snapshot)) {
                            throw new RuntimeException('Snapshot router tidak lengkap. Edit dibatalkan.');
                        }
                        $sebelum[$key] = $snapshot[$key];
                    }
                    $langkah[] = $sebelum;
                }
                unset($langkah);
                foreach ($rencana as [$path, $objekId, $nilai, $sebelum]) {
                    // Catat sebelum request, termasuk ketika respons hilang setelah router menulis.
                    $ubah[] = [$path, $objekId, $sebelum];
                    $this->router->ubah($path, $objekId, $nilai);
                }
                if ($data['putus_sesi']) {
                    foreach ($this->router->daftar('ppp/active') as $sesi) {
                        if (! in_array($sesi['name'] ?? null, [$akun->username, $data['username']], true)) {
                            continue;
                        }
                        try {
                            $this->router->hapus('ppp/active', $sesi['.id']);
                        } catch (RouterOsException $e) {
                            if ($e->statusHttp !== 404) {
                                throw $e;
                            }
                        }
                    }
                }
                $akun->fill(array_intersect_key($data, array_flip(['username', 'vps_id', 'paket_bandwidth_id'])));
                if (! empty($data['password'])) {
                    $akun->password = $data['password'];
                }
                $akun->forceFill(['disinkron_pada' => now(), 'pesan_error' => null, 'diubah_oleh' => $operasi->dipicu_oleh])->save();
                AuditLog::create([
                    'user_id' => $operasi->dipicu_oleh, 'aksi' => 'edit_akun', 'objek_tipe' => 'AkunVpn', 'objek_id' => $akun->id,
                    'deskripsi' => 'Edit akun VPN berhasil diterapkan ke router.', 'data_lama' => $lama,
                    'data_baru' => $akun->only(['username', 'vps_id', 'paket_bandwidth_id']) + ['password_diubah' => ! empty($data['password']), 'putus_sesi' => $data['putus_sesi']],
                    'ip_admin' => $operasi->payload['ip_admin'] ?? '0.0.0.0',
                ]);
                $operasi->update(['status' => 'sukses', 'selesai_pada' => now(), 'durasi_ms' => (int) ((hrtime(true) - $mulai) / 1000000)]);
            });
        } catch (Throwable $e) {
            $rollbackGagal = false;
            foreach (array_reverse($ubah) as [$path, $objekId, $sebelum]) {
                try {
                    $this->router->ubah($path, $objekId, $sebelum);
                } catch (Throwable) {
                    $rollbackGagal = true;
                }
            }
            // Pesan RouterOS dapat memuat input sensitif. Jangan simpan pesan mentahnya.
            $pesan = $e instanceof RuntimeException && ! $e instanceof RouterOsException && ! $e instanceof QueryException
                ? $e->getMessage() : 'Edit gagal. Periksa koneksi, username di router, dan kelengkapan objek lalu coba kembali.';
            if ($rollbackGagal) {
                $pesan = 'Edit gagal dan pemulihan router belum lengkap. Periksa sinkronisasi sebelum mencoba kembali.';
            }
            $operasi->update(['status' => 'gagal', 'pesan_error' => $pesan, 'selesai_pada' => now(), 'durasi_ms' => (int) ((hrtime(true) - $mulai) / 1000000)]);
        }
    }
}
