<?php

return [
    // REST API RouterOS v7. Sertifikat CHR di lab bersifat self-signed,
    // sehingga verify default false. Wajib disebut di batasan laporan.
    'base_url'   => env('ROUTEROS_BASE_URL', 'https://192.168.56.10'),
    'user'       => env('ROUTEROS_USER', 'api-laravel'),
    'password'   => env('ROUTEROS_PASSWORD', ''),
    'verify_tls' => env('ROUTEROS_VERIFY_TLS', false),
    'timeout'    => (int) env('ROUTEROS_TIMEOUT', 10),

    // Dipakai email kredensial; klien L2TP memerlukannya untuk terhubung.
    'ipsec_psk'  => env('ROUTEROS_IPSEC_PSK', ''),

    // Alamat server VPN dari sudut pandang KLIEN. Berbeda dari base_url:
    // base_url adalah jalur manajemen (host-only) yang hanya dapat dijangkau
    // server Laravel, sedangkan klien terhubung lewat alamat LAN/publik.
    // Mengirim alamat manajemen ke pemohon membuat koneksi selalu gagal.
    'vpn_server' => env('ROUTEROS_VPN_SERVER', ''),

    // Setiap objek yang dibuat sistem diberi comment berawalan ini.
    // Dipakai deteksi drift untuk membedakan objek milik sistem dari objek
    // yang dibuat manual oleh admin lewat Winbox.
    'comment_prefix' => env('ROUTEROS_COMMENT_PREFIX', 'vpnlc'),

    // Nama objek yang harus sudah disiapkan di router (di luar lingkup sistem).
    'ip_pool'          => env('ROUTEROS_IP_POOL', 'vpn-pool'),

    // Rentang alamat yang dibagikan ke klien VPN. HARUS sama dengan
    // /ip pool di router. IP tidak pernah didaur ulang (lihat AlokasiIp).
    'pool_range'       => env('ROUTEROS_POOL_RANGE', '10.10.20.10-10.10.20.250'),

    // Alamat router di sisi terowongan; dipakai sebagai local-address dan
    // alamat DNS pada PPP profile.
    'local_address'    => env('ROUTEROS_LOCAL_ADDRESS', '10.10.20.1'),

    // Comment aturan firewall tolak-default yang dipasang chr-setup.rsc.
    // Aturan accept per akun disisipkan TEPAT DI ATAS aturan ini.
    'aturan_tolak'     => env('ROUTEROS_ATURAN_TOLAK', 'vpnlc:sistem - tolak default'),
    'address_list'     => env('ROUTEROS_ADDRESS_LIST', 'vpn-klien'),
    'firewall_chain'   => env('ROUTEROS_FIREWALL_CHAIN', 'forward'),
];
