# =====================================================================
#  Konfigurasi MikroTik CHR - Sistem Manajemen Siklus Hidup Akun VPN
#  Jalankan:  /import file=chr-setup.rsc
#  Adapter: ether1 = bridged (LAN, klien VPN + internet),
#           ether2 = host-only 192.168.56.2 (REST API, diasumsikan SUDAH dipasang),
#           ether3 = internal "VPS Network" (VPS).
#
#  Isi <ROUTEROS_PASSWORD> dan <IPSEC_PSK> lebih dulu, mis:
#    sed -e "s|<ROUTEROS_PASSWORD>|nilai|" -e "s|<IPSEC_PSK>|nilai|" \
#        chr-setup.rsc > chr-setup.local.rsc
# =====================================================================

:put "== 1/12 Alamat jaringan VPS Network =="
/ip address
add address=10.10.20.1/24 interface=ether3 comment="VPS Network - gateway VPS"

:put "== 2/12 Sertifikat REST API =="
/certificate
add name=api-cert common-name=chr.lab key-size=2048 days-valid=3650
sign api-cert
:put "   menunggu penandatanganan sertifikat..."
:delay 15s

:put "== 3/12 Service =="
/ip service
set www-ssl certificate=api-cert disabled=no port=443
set www disabled=no port=80
set telnet disabled=yes
set ftp disabled=yes
# Lapis 1: service manajemen hanya menjawab dari jaringan manajemen (ether2,
# tempat Laravel). Dari LAN atau pool VPN, service ini tidak merespons.
set ssh address=192.168.56.0/24
set winbox address=192.168.56.0/24
set www address=192.168.56.0/24
set www-ssl address=192.168.56.0/24
set api address=192.168.56.0/24
set api-ssl address=192.168.56.0/24

:put "== 4/12 Pengguna API =="
/user group
add name=api-lifecycle policy=read,write,api,rest-api,test,winbox,password
/user
add name=api-laravel group=api-lifecycle password=<ROUTEROS_PASSWORD> comment="dipakai Laravel"

:put "== 5/12 Pool alamat klien VPN =="
/ip pool
add name=vpn-pool ranges=10.10.20.10-10.10.20.250

:put "== 6/12 PPP profile per paket bandwidth =="
# TIDAK dibuat di sini. Tiap paket bandwidth ditambahkan admin lewat menu
# Pengaturan di web, dan SelaraskanPaketJob membuat PPP profile-nya di router
# (rate-limit, local-address, remote-address=pool, dns-server) saat itu.

:put "== 7/12 L2TP server + IPsec =="
/interface l2tp-server server
set enabled=yes use-ipsec=required ipsec-secret=<IPSEC_PSK> default-profile=default-encryption authentication=mschap2

:put "== 8/12 Firewall - izinkan L2TP masuk =="
/ip firewall filter
add chain=input connection-state=established,related action=accept comment="vpnlc:sistem - koneksi berjalan"
add chain=input protocol=udp port=500,1701,4500 action=accept comment="vpnlc:sistem - L2TP/IPsec"
add chain=input protocol=ipsec-esp action=accept comment="vpnlc:sistem - ESP"

:put "== 9/12 Firewall - router tertutup kecuali dari jaringan manajemen =="
# Lapis 2. chain=input = trafik TUJUAN router itu sendiri (SSH/Winbox/API/web).
# Default RouterOS untuk chain=input adalah ACCEPT, jadi tanpa rule ini siapa pun
# di LAN (termasuk pemilik akun VPN yang satu jaringan) bisa login ke router.
# Klien VPN hanya boleh DNS ke router (dns-server profile = 10.10.20.1).
add chain=input in-interface=ether1 protocol=udp dst-port=68 action=accept comment="vpnlc:sistem - DHCP client ether1"
# Ping boleh (cek server VPN hidup), service manajemen tetap tertutup.
# 8:0 = echo request saja; limit mencegah ping flood.
add chain=input protocol=icmp icmp-options=8:0 limit=10,20:packet action=accept comment="vpnlc:sistem - izinkan ping"
add chain=input src-address=10.10.20.0/24 protocol=udp dst-port=53 action=accept comment="vpnlc:sistem - DNS klien VPN"
add chain=input src-address=10.10.20.0/24 protocol=tcp dst-port=53 action=accept comment="vpnlc:sistem - DNS klien VPN tcp"
add chain=input src-address=10.10.20.0/24 action=drop comment="vpnlc:sistem - blokir manajemen dari pool VPN"
add chain=input in-interface=ether1 action=drop comment="vpnlc:sistem - blokir manajemen dari LAN"

:put "== 10/12 Firewall - isolasi klien VPN =="
# Aturan tolak-default. Sistem menyisipkan aturan accept per akun DI ATAS ini.
# Akun tanpa aturan accept otomatis tidak bisa menjangkau VPS mana pun.
/ip firewall filter
add chain=forward src-address=10.10.20.0/24 dst-address=10.10.20.0/24 action=drop comment="vpnlc:sistem - isolasi antar klien"
add chain=forward src-address=10.10.20.0/24 dst-address=10.10.10.0/24 action=drop comment="vpnlc:sistem - tolak default"

:put "== 11/12 Internet untuk klien VPN =="
# Trafik klien ke internet sebenarnya sudah lolos chain forward; yang hilang
# adalah NAT, sehingga paket keluar tetapi balasannya tidak tahu jalan pulang.
# ether1 = interface bridged yang menuju jaringan luar.
/ip firewall nat
add chain=srcnat src-address=10.10.20.0/24 out-interface=ether1 action=masquerade comment="vpnlc:sistem - internet untuk klien VPN"

:put "== 12/12 DNS untuk klien VPN =="
# Tanpa ini klien terhubung tetapi tidak bisa membuka nama domain apa pun.
/ip dns
set servers=8.8.8.8,1.1.1.1 allow-remote-requests=yes
# dns-server per PPP profile diisi SelaraskanPaketJob saat paket dibuat di web.

:put ""
:put "SELESAI. Verifikasi dari Laravel: php artisan router:cek"
