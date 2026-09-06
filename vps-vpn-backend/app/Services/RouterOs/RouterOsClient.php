<?php

namespace App\Services\RouterOs;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pembungkus tipis REST API RouterOS v7.
 *
 * Sengaja tidak menyentuh basis data dan tidak mencatat apa pun: pencatatan ke
 * tabel operasi_router adalah tugas job pemanggil, supaya kelas ini bisa diuji
 * tanpa database dan tanpa router.
 *
 * Endpoint RouterOS memakai path menu yang dipisah garis miring, mis.
 * 'ppp/secret', 'ip/firewall/address-list', 'system/resource'.
 */
class RouterOsClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $user,
        private readonly string $password,
        private readonly bool $verifyTls = false,
        private readonly int $timeout = 10,
    ) {}

    public static function dariConfig(): self
    {
        return new self(
            rtrim((string) config('routeros.base_url'), '/'),
            (string) config('routeros.user'),
            (string) config('routeros.password'),
            (bool) config('routeros.verify_tls'),
            (int) config('routeros.timeout'),
        );
    }

    /** Daftar isi sebuah menu. @return array<int,array<string,mixed>> */
    public function daftar(string $path, array $query = []): array
    {
        return $this->kirim('get', $path, query: $query);
    }

    /** Ambil satu objek berdasarkan .id RouterOS (mis. *1A). */
    public function ambil(string $path, string $id): array
    {
        return $this->kirim('get', $path . '/' . $id);
    }

    /** Buat objek baru; balasan berisi .id yang harus disimpan di basis data. */
    public function buat(string $path, array $data): array
    {
        return $this->kirim('put', $path, $data);
    }

    /** Ubah sebagian atribut objek. */
    public function ubah(string $path, string $id, array $data): array
    {
        return $this->kirim('patch', $path . '/' . $id, $data);
    }

    public function hapus(string $path, string $id): void
    {
        $this->kirim('delete', $path . '/' . $id);
    }

    /**
     * Jalankan perintah RouterOS (bukan CRUD), mis. 'ping', 'ppp/active/remove'.
     * Di REST API perintah dikirim sebagai POST ke path menu.
     */
    public function perintah(string $path, array $data = []): array
    {
        return $this->kirim('post', $path, $data);
    }

    /** Uji koneksi; mengembalikan ringkasan identitas router. */
    public function cekKoneksi(): array
    {
        $sumber   = $this->kirim('get', 'system/resource');
        $identity = $this->kirim('get', 'system/identity');

        return [
            'identity'   => $identity['name'] ?? ($identity[0]['name'] ?? '-'),
            'versi'      => $sumber['version'] ?? ($sumber[0]['version'] ?? '-'),
            'board'      => $sumber['board-name'] ?? ($sumber[0]['board-name'] ?? '-'),
            'uptime'     => $sumber['uptime'] ?? ($sumber[0]['uptime'] ?? '-'),
            'arsitektur' => $sumber['architecture-name'] ?? ($sumber[0]['architecture-name'] ?? '-'),
        ];
    }

    /**
     * Ping dari sudut pandang ROUTER, bukan dari server Laravel.
     * Klien VPN keluar lewat router ini, jadi hasilnya mewakili jalur nyata.
     *
     * @return array{status:string, rtt_avg_ms:?float, packet_loss:int}
     */
    public function ping(string $alamat, int $jumlah = 3): array
    {
        $hasil = $this->perintah('ping', ['address' => $alamat, 'count' => (string) $jumlah]);

        $diterima = 0;
        $totalRtt = 0.0;
        $adaRtt    = 0;

        foreach ($hasil as $baris) {
            if (($baris['status'] ?? null) === 'timeout') {
                continue;
            }
            if (isset($baris['time'])) {
                $diterima++;
                $totalRtt += $this->msDariWaktu((string) $baris['time']);
                $adaRtt++;
            } elseif (isset($baris['received']) && (int) $baris['received'] > 0) {
                $diterima++;
            }
        }

        $loss = $jumlah > 0 ? (int) round((($jumlah - $diterima) / $jumlah) * 100) : 100;

        return [
            'status'      => $diterima > 0 ? 'up' : 'down',
            'rtt_avg_ms'  => $adaRtt > 0 ? round($totalRtt / $adaRtt, 2) : null,
            'packet_loss' => max(0, min(100, $loss)),
        ];
    }

    /** RouterOS mengembalikan waktu seperti "1ms200us", "560us", "2s100ms". */
    public function msDariWaktu(string $waktu): float
    {
        preg_match_all('/(\d+(?:\.\d+)?)(ms|us|s)/', $waktu, $cocok, PREG_SET_ORDER);

        $ms = 0.0;
        foreach ($cocok as [, $angka, $satuan]) {
            $ms += match ($satuan) {
                's'  => ((float) $angka) * 1000,
                'ms' => (float) $angka,
                'us' => ((float) $angka) / 1000,
            };
        }

        return $ms;
    }

    private function permintaan(): PendingRequest
    {
        return Http::withBasicAuth($this->user, $this->password)
            ->withOptions(['verify' => $this->verifyTls])
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }

    private function kirim(string $metode, string $path, array $data = [], array $query = []): array
    {
        $url = $this->baseUrl . '/rest/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        try {
            /** @var Response $res */
            $res = $this->permintaan()->{$metode}($url, $data);
        } catch (Throwable $e) {
            throw new RouterOsException(
                "Tidak dapat menghubungi router di {$this->baseUrl}: " . $e->getMessage(),
            );
        }

        if ($res->failed()) {
            $badan = $res->json() ?? [];
            $pesan = $badan['detail'] ?? $badan['message'] ?? $res->body();

            throw new RouterOsException(
                "RouterOS menolak {$metode} {$path} ({$res->status()}): {$pesan}",
                $res->status(),
                is_array($badan) ? $badan : null,
            );
        }

        $badan = $res->json();

        return is_array($badan) ? $badan : [];
    }
}
