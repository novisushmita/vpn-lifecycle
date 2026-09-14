<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Menandai job apa yang sedang memakai router, supaya operasi lain yang
 * ketumpuk di belakangnya (mis. ping manual pas job terjadwal jalan) bisa
 * dikasih tahu sedang menunggu apa, bukan cuma "belum selesai".
 *
 * Aman tanpa lock: worker queue cuma satu proses (CLAUDE.md 6.2), jadi tidak
 * pernah ada dua job jalan bersamaan yang bisa saling timpa penanda ini.
 */
class PenandaJobRouter
{
    private const KUNCI = 'router_job_aktif';

    public static function mulai(string $label): void
    {
        Cache::put(self::KUNCI, $label, now()->addMinutes(10));
    }

    public static function selesai(): void
    {
        Cache::forget(self::KUNCI);
    }

    public static function aktif(): ?string
    {
        return Cache::get(self::KUNCI);
    }
}
