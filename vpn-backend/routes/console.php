<?php

use App\Services\Pengaturan;
use Illuminate\Support\Facades\Schedule;

/**
 * Selang waktu diambil dari pengaturan. Berkas ini dijalankan pada SETIAP
 * perintah artisan, termasuk saat basis data belum siap, sehingga pembacaannya
 * harus selalu punya nilai jatuhan.
 */
$selang = static function (string $kunci, int $bawaan): int {
    try {
        return Pengaturan::angka($kunci);
    } catch (Throwable) {
        return $bawaan;
    }
};

/*
|--------------------------------------------------------------------------
| Pekerjaan terjadwal
|--------------------------------------------------------------------------
| Dijalankan oleh satu proses: php artisan schedule:work
| Di server produksi dipasang sebagai entri cron menit-an ke schedule:run.
|
| withoutOverlapping mencegah dua eksekusi bertumpuk ketika router lambat
| merespons — tanpa itu, polling 30 detik bisa saling menyusul.
*/

$detikSesi = $selang('detik_polling_sesi', 30);

// Log sesi — mendeteksi sesi baru dan sesi yang putus.
$pollingSesi = Schedule::command('vpn:polling-sesi');

// Penjadwal Laravel hanya menyediakan selang sub-menit tertentu, jadi nilai
// pengaturan dibulatkan ke pilihan terdekat yang didukung.
match (true) {
    $detikSesi <= 10 => $pollingSesi->everyTenSeconds(),
    $detikSesi <= 15 => $pollingSesi->everyFifteenSeconds(),
    $detikSesi <= 20 => $pollingSesi->everyTwentySeconds(),
    $detikSesi <= 30 => $pollingSesi->everyThirtySeconds(),
    default          => $pollingSesi->everyMinute(),
};

$pollingSesi
    ->withoutOverlapping()
    ->runInBackground();

// Ketersediaan VPS dari sisi router.
Schedule::command('vps:ping')
    ->cron('*/' . $selang('menit_ping_vps', 5) . ' * * * *')
    ->withoutOverlapping();

// Deteksi ketidaksesuaian basis data dengan router.
Schedule::command('vpn:sinkron')
    ->cron('*/' . $selang('menit_periksa_drift', 10) . ' * * * *')
    ->withoutOverlapping();

// Peringatan H-3, kedaluwarsa, dan pembersihan H+30.
Schedule::command('vpn:kedaluwarsa')
    ->dailyAt('01:00')
    ->withoutOverlapping();

// Menyegarkan status koneksi router untuk dashboard.
Schedule::job(new App\Jobs\CekKoneksiRouterJob())
    ->everyMinute()
    ->withoutOverlapping();

// Menjaga tabel riwayat ping tidak tumbuh tanpa batas.
Schedule::call(function () {
    App\Models\VpsHealthCheck::where('checked_at', '<', now()->subDays(Pengaturan::angka('hari_simpan_riwayat_ping')))->delete();
})->weekly();
