<?php

namespace Database\Seeders;

use App\Models\PaketBandwidth;
use Illuminate\Database\Seeder;

class PaketBandwidthSeeder extends Seeder
{
    public function run(): void
    {
        // Keputusan #7: paket bertingkat, tanpa kuota volume.
        // ppp_profile harus sudah ada di RouterOS dengan nama yang sama.
        $paket = [
            ['nama' => 'Dasar',     'rx_rate' => '2M',  'tx_rate' => '2M',  'ppp_profile' => 'vpn-dasar',     'keterangan' => 'Akses ringan: monitoring, pengecekan berkala.'],
            ['nama' => 'Standar',   'rx_rate' => '5M',  'tx_rate' => '5M',  'ppp_profile' => 'vpn-standar',   'keterangan' => 'Akses umum: maintenance dan deployment aplikasi.'],
            ['nama' => 'Prioritas', 'rx_rate' => '10M', 'tx_rate' => '10M', 'ppp_profile' => 'vpn-prioritas', 'keterangan' => 'Akses berat: pemulihan atau pemindahan data besar.'],
        ];

        foreach ($paket as $p) {
            PaketBandwidth::updateOrCreate(['nama' => $p['nama']], $p);
        }
    }
}
