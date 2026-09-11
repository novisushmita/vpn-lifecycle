<?php

/*
|--------------------------------------------------------------------------
| Nilai bawaan pengaturan yang dapat diubah admin
|--------------------------------------------------------------------------
| Satu-satunya sumber nilai bawaan. Tabel `pengaturan` hanya menyimpan yang
| benar-benar diubah, jadi menghapus barisnya berarti kembali ke sini.
*/

return [
    'bawaan' => [
        // --- Layanan ---
        'alamat_server_vpn' => [
            'nilai'      => env('ROUTEROS_VPN_SERVER', ''),
            'label'      => 'Alamat server VPN',
            'bantuan'    => 'Alamat yang dikirim ke pemohon lewat email dan dipakai klien untuk terhubung. Bukan alamat manajemen router. Ubah di sini bila alamat LAN router berubah, tanpa menyentuh .env.',
            'tipe'       => 'teks',
        ],
        'rentang_pool_vpn' => [
            'nilai'      => env('ROUTEROS_POOL_RANGE', '10.10.20.10-10.10.20.250'),
            'label'      => 'Rentang alamat klien VPN',
            'bantuan'    => 'Rentang IP yang dialokasikan ke akun, format awal-akhir. HARUS sama persis dengan /ip pool di router.',
            'tipe'       => 'teks',
        ],

        // --- Pemeriksaan VPS ---
        'batas_kegagalan_ping' => [
            'nilai'      => 3,
            'label'      => 'Batas kegagalan berturut-turut',
            'bantuan'    => 'Jumlah ping gagal berurutan sebelum VPS ditandai bermasalah. Nilai 1 membuat satu paket hilang langsung memunculkan alarm.',
            'tipe'       => 'angka',
            'min'        => 1,
            'max'        => 10,
        ],
        'menit_ping_vps' => [
            'nilai'      => 5,
            'label'      => 'Selang pemeriksaan VPS (menit)',
            'bantuan'    => 'Seberapa sering ketersediaan seluruh VPS diperiksa dari sisi router.',
            'tipe'       => 'angka',
            'min'        => 1,
            'max'        => 60,
        ],
        'hari_simpan_riwayat_ping' => [
            'nilai'      => 90,
            'label'      => 'Lama simpan riwayat pemeriksaan (hari)',
            'bantuan'    => 'Riwayat ping yang lebih tua dari ini dibuang otomatis.',
            'tipe'       => 'angka',
            'min'        => 7,
            'max'        => 365,
        ],

        // --- Sesi & sinkronisasi ---
        'detik_polling_sesi' => [
            'nilai'      => 30,
            'label'      => 'Selang pencatatan sesi (detik)',
            'bantuan'    => 'Menentukan ketelitian durasi dan volume data sesi. Penjadwal membulatkan ke pilihan terdekat yang didukung: 10, 15, 20, 30, atau 60 detik.',
            'tipe'       => 'angka',
            'min'        => 10,
            'max'        => 60,
        ],
        'menit_periksa_drift' => [
            'nilai'      => 10,
            'label'      => 'Selang pemeriksaan router (menit)',
            'bantuan'    => 'Seberapa sering data sistem dibandingkan dengan kondisi sebenarnya di router.',
            'tipe'       => 'angka',
            'min'        => 1,
            'max'        => 1440,
        ],

        // --- Masa berlaku akun ---
        'hari_peringatan_kedaluwarsa' => [
            'nilai'      => 3,
            'label'      => 'Peringatan sebelum berakhir (hari)',
            'bantuan'    => 'Email peringatan dikirim sekian hari sebelum masa akses berakhir. Turunkan ke 1 bila ingin memperagakan alurnya tanpa menunggu lama.',
            'tipe'       => 'angka',
            'min'        => 1,
            'max'        => 30,
        ],
        'hari_pembersihan_kedaluwarsa' => [
            'nilai'      => 30,
            'label'      => 'Hapus setelah berakhir (hari)',
            'bantuan'    => 'Akun yang sudah berakhir dihapus penuh dari router setelah tenggang ini. Riwayat sesi dan jejak audit tetap disimpan.',
            'tipe'       => 'angka',
            'min'        => 1,
            'max'        => 365,
        ],
    ],
];
