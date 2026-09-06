# =====================================================================
#  Konfigurasi MikroTik CHR - Sistem Manajemen Siklus Hidup Akun VPN
#  Jalankan:  /import file=chr-setup.rsc
#  Alamat ether1 (192.168.56.10) diasumsikan SUDAH dipasang manual.
#
#  Isi <ROUTEROS_PASSWORD> dan <IPSEC_PSK> lebih dulu, mis:
#    sed -e "s|<ROUTEROS_PASSWORD>|nilai|" -e "s|<IPSEC_PSK>|nilai|" \
#        chr-setup.rsc > chr-setup.local.rsc
# =====================================================================

:put "== 1/11 Alamat jaringan vpslan =="
/ip address
add address=10.10.10.1/24 interface=ether2 comment="vpslan - gateway VPS"

:put "== 2/11 Sertifikat REST API =="
/certificate
add name=api-cert common-name=chr.lab key-size=2048 days-valid=3650
sign api-cert
:put "   menunggu penandatanganan sertifikat..."
:delay 15s

:put "== 3/11 Service =="
/ip service
set www-ssl certificate=api-cert disabled=no port=443
set www disabled=no port=80
set telnet disabled=yes
set ftp disabled=yes

:put "== 4/11 Pengguna API =="
/user group
add name=api-lifecycle policy=read,write,api,rest-api,test,winbox,password
/user
add name=api-laravel group=api-lifecycle password=<ROUTEROS_PASSWORD> comment="dipakai Laravel"

:put "== 5/11 Pool alamat klien VPN =="
/ip pool
add name=vpn-pool ranges=10.20.0.10-10.20.0.250

:put "== 6/11 PPP profile per paket bandwidth =="
# Nama HARUS sama dengan kolom ppp_profile di tabel paket_bandwidth.
/ppp profile
add name=vpn-dasar local-address=10.20.0.1 remote-address=vpn-pool rate-limit=2M/2M use-encryption=yes change-tcp-mss=yes
add name=vpn-standar local-address=10.20.0.1 remote-address=vpn-pool rate-limit=5M/5M use-encryption=yes change-tcp-mss=yes
add name=vpn-prioritas local-address=10.20.0.1 remote-address=vpn-pool rate-limit=10M/10M use-encryption=yes change-tcp-mss=yes

:put "== 7/11 L2TP server + IPsec =="
/interface l2tp-server server
set enabled=yes use-ipsec=required ipsec-secret=<IPSEC_PSK> default-profile=vpn-standar authentication=mschap2

:put "== 8/11 Firewall - izinkan L2TP masuk =="
/ip firewall filter
add chain=input protocol=udp port=500,1701,4500 action=accept comment="vpnlc:sistem - L2TP/IPsec"
add chain=input protocol=ipsec-esp action=accept comment="vpnlc:sistem - ESP"

:put "== 9/11 Firewall - isolasi klien VPN =="
# Aturan tolak-default. Sistem menyisipkan aturan accept per akun DI ATAS ini.
# Akun tanpa aturan accept otomatis tidak bisa menjangkau VPS mana pun.
/ip firewall filter
add chain=forward src-address=10.20.0.0/24 dst-address=10.20.0.0/24 action=drop comment="vpnlc:sistem - isolasi antar klien"
add chain=forward src-address=10.20.0.0/24 dst-address=10.10.10.0/24 action=drop comment="vpnlc:sistem - tolak default"

:put "== 10/11 Internet untuk klien VPN =="
# Trafik klien ke internet sebenarnya sudah lolos chain forward; yang hilang
# adalah NAT, sehingga paket keluar tetapi balasannya tidak tahu jalan pulang.
# ether3 = interface yang menuju jaringan luar (NAT atau bridged).
/ip firewall nat
add chain=srcnat src-address=10.20.0.0/24 out-interface=ether3 action=masquerade comment="vpnlc:sistem - internet untuk klien VPN"

:put "== 11/11 DNS untuk klien VPN =="
# Tanpa ini klien terhubung tetapi tidak bisa membuka nama domain apa pun.
/ip dns
set servers=8.8.8.8,1.1.1.1 allow-remote-requests=yes
/ppp profile
set [find name=vpn-dasar]     dns-server=10.20.0.1
set [find name=vpn-standar]   dns-server=10.20.0.1
set [find name=vpn-prioritas] dns-server=10.20.0.1

:put ""
:put "SELESAI. Verifikasi dari Laravel: php artisan router:cek"
