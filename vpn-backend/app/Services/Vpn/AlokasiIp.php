<?php

namespace App\Services\Vpn;

use App\Models\AkunVpn;
use App\Services\Pengaturan;
use RuntimeException;

/**
 * Memilih alamat VPN berikutnya dari pool.
 *
 * IP TIDAK PERNAH DIDAUR ULANG: baris yang sudah di-soft-delete tetap
 * dihitung sebagai terpakai. Alasannya, bila ada aturan firewall lama yang
 * tertinggal karena penghapusan gagal separuh jalan, ia tidak akan pernah
 * cocok dengan akun baru.
 */
class AlokasiIp
{
    public function __construct(private readonly string $rentang) {}

    public static function dariConfig(): self
    {
        // Bisa diubah admin lewat menu Pengaturan; .env jadi nilai bawaan.
        return new self((string) Pengaturan::ambil('rentang_pool_vpn', config('routeros.pool_range')));
    }

    public function berikutnya(): string
    {
        [$awal, $akhir] = $this->batas();

        $terpakai = AkunVpn::withTrashed()
            ->whereNotNull('ip_vpn')
            ->pluck('ip_vpn')
            ->map(fn ($ip) => ip2long($ip))
            ->filter()
            ->flip();

        for ($n = $awal; $n <= $akhir; $n++) {
            if (! $terpakai->has($n)) {
                return long2ip($n);
            }
        }

        throw new RuntimeException(
            "Pool alamat VPN habis ({$this->rentang}). Perluas rentang di router dan di ROUTEROS_POOL_RANGE."
        );
    }

    public function jumlahTersisa(): int
    {
        [$awal, $akhir] = $this->batas();
        $total = $akhir - $awal + 1;

        return max(0, $total - AkunVpn::withTrashed()->whereNotNull('ip_vpn')->count());
    }

    /** @return array{0:int,1:int} */
    private function batas(): array
    {
        $bagian = explode('-', $this->rentang, 2);

        $awal  = ip2long(trim($bagian[0] ?? ''));
        $akhir = ip2long(trim($bagian[1] ?? ''));

        if ($awal === false || $akhir === false || $awal > $akhir) {
            throw new RuntimeException("Rentang pool tidak valid: {$this->rentang}");
        }

        return [$awal, $akhir];
    }
}
