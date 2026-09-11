<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Paket bandwidth sengaja tidak di-seed: admin menambahkannya sendiri
        // lewat menu Pengaturan, dan tiap paket membuat PPP profile-nya di
        // router. Untuk mengisi tiga paket contoh secara manual:
        //   php artisan db:seed --class=Database\\Seeders\\PaketBandwidthSeeder
        $this->call([
            AdminSeeder::class,
        ]);
    }
}
