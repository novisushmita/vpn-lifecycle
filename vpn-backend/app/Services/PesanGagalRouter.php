<?php

namespace App\Services;

use App\Services\RouterOs\RouterOsException;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * Menyaring pesan exception sebelum disimpan/ditampilkan ke admin.
 *
 * RouterOsException dan QueryException memuat detail teknis (URL router,
 * path REST, status HTTP, pesan SQL) yang tidak berguna bagi admin dan tidak
 * boleh bocor ke UI. Hanya RuntimeException murni — yang di kelas ini memang
 * sengaja dilempar dengan kalimat Indonesia yang sudah aman dibaca admin —
 * yang boleh diteruskan apa adanya.
 */
class PesanGagalRouter
{
    public static function aman(Throwable $e, string $default): string
    {
        $amanDitampilkan = $e instanceof RuntimeException
            && ! $e instanceof RouterOsException
            && ! $e instanceof QueryException;

        return $amanDitampilkan ? $e->getMessage() : $default;
    }
}
