<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pembaca dan penulis pengaturan yang dapat diubah admin.
 *
 * Nilai bawaan ada di config/pengaturan.php; tabel hanya menyimpan yang
 * benar-benar diubah. Seluruh pembacaan dibungkus try/catch karena berkas
 * jadwal memanggilnya pada SETIAP perintah artisan, termasuk saat migrasi
 * belum dijalankan dan tabelnya belum ada.
 */
class Pengaturan
{
    private const KUNCI_CACHE = 'pengaturan.semua';

    /** @return array<string,mixed> */
    public static function semua(): array
    {
        $bawaan = collect(config('pengaturan.bawaan'))->map(fn ($d) => $d['nilai'])->all();

        try {
            $tersimpan = Cache::remember(
                self::KUNCI_CACHE,
                300,
                fn () => DB::table('pengaturan')->pluck('nilai', 'kunci')->all()
            );
        } catch (Throwable) {
            return $bawaan;
        }

        return array_merge($bawaan, array_filter($tersimpan, fn ($v) => $v !== null));
    }

    public static function ambil(string $kunci, mixed $bawaan = null): mixed
    {
        return self::semua()[$kunci] ?? $bawaan ?? config("pengaturan.bawaan.{$kunci}.nilai");
    }

    /** Pembacaan angka; selalu mengembalikan int yang masuk akal. */
    public static function angka(string $kunci): int
    {
        $meta = config("pengaturan.bawaan.{$kunci}");
        $n    = (int) self::ambil($kunci, $meta['nilai'] ?? 0);

        return max($meta['min'] ?? 1, min($meta['max'] ?? PHP_INT_MAX, $n));
    }

    public static function simpan(string $kunci, mixed $nilai, ?int $olehUserId = null): void
    {
        DB::table('pengaturan')->updateOrInsert(
            ['kunci' => $kunci],
            ['nilai' => (string) $nilai, 'diubah_oleh' => $olehUserId, 'updated_at' => now(), 'created_at' => now()],
        );

        Cache::forget(self::KUNCI_CACHE);
    }

    public static function lupakanCache(): void
    {
        Cache::forget(self::KUNCI_CACHE);
    }
}
