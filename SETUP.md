# Catatan Pemasangan & Reproduksi Lingkungan

Langkah pemasangan sistem, ditulis supaya proses bisa diulang di perangkat
lain dan dikutip untuk bab implementasi. Seluruh langkah sudah dieksekusi dan
diverifikasi pada 2026-09-05.

---

## 1. Spesifikasi lingkungan

| Komponen | Versi | Cara cek |
|---|---|---|
| Sistem operasi | Kali GNU/Linux (kernel 5.17.0-kali3-amd64) | `uname -a` |
| PHP | 8.4.24 (CLI, NTS) | `php -v` |
| Composer | 2.10.3 | `composer --version` |
| Laravel Framework | 13.30.1 | `php artisan --version` |
| MariaDB Server | 11.8.8-1 | `mariadb --version` |
| Node.js | untuk frontend Vue | `node -v` |

Opsional: `sudo apt install php-intl` (hanya untuk perintah kosmetik
`php artisan db:show --counts`; tidak memengaruhi aplikasi).

---

## 2. Struktur direktori

```
TUGAS AKHIR NOVICAN/
├── CLAUDE.md              # dokumen acuan sistem
├── SETUP.md               # berkas ini
├── vpn-frontend/      # Vue 3 + Vite + Vue Router
└── vpn-backend/       # Laravel 13
```

---

## 3. Pemasangan MariaDB

```bash
sudo apt install -y mariadb-server
sudo systemctl enable --now mariadb
```

Bila muncul galat `Unknown modifier 'u!'` saat konfigurasi paket, jalankan:

```bash
sudo sed -i 's/^u! /u /' /usr/lib/sysusers.d/mariadb.conf
sudo dpkg --configure mariadb-server
sudo systemctl enable --now mariadb
```

---

## 4. Basis data dan pengguna

Aplikasi **tidak memakai akun `root`**. Akun `root` MariaDB memakai
autentikasi `unix_socket` sehingga hanya bisa dipakai lewat `sudo mysql` di
terminal lokal, dan hak aksesnya terlalu luas untuk aplikasi.

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS vpn_lifecycle \
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

sudo mysql -e "
CREATE USER IF NOT EXISTS 'vpn_app'@'localhost' IDENTIFIED BY '<DB_PASSWORD>';
CREATE USER IF NOT EXISTS 'vpn_app'@'127.0.0.1' IDENTIFIED BY '<DB_PASSWORD>';
GRANT ALL PRIVILEGES ON vpn_lifecycle.* TO 'vpn_app'@'localhost';
GRANT ALL PRIVILEGES ON vpn_lifecycle.* TO 'vpn_app'@'127.0.0.1';
FLUSH PRIVILEGES;"
```

Pengguna dibuat untuk **dua host sekaligus**. MariaDB memperlakukan
`'user'@'localhost'` dan `'user'@'127.0.0.1'` sebagai identitas berbeda, dan
koneksi TCP dari Laravel dapat cocok ke salah satunya bergantung resolusi DNS
balik. Mendaftarkan keduanya menghilangkan ketidakpastian.

---

## 5. Pemasangan backend Laravel

```bash
cd "TUGAS AKHIR NOVICAN"
composer create-project laravel/laravel vpn-backend --no-interaction
cd vpn-backend
rm -f database/database.sqlite     # proyek ini memakai MySQL, bukan SQLite
```

SQLite tidak dipakai karena skema banyak memakai kolom `enum`, sedangkan
SQLite tidak memiliki tipe tersebut sehingga nilainya tidak tervalidasi.

Isi `.env` sesuai bagian 6, lalu:

```bash
php artisan key:generate
php artisan migrate
php artisan db:seed
```

---

## 6. Kredensial lingkungan pengembangan

> **Nilai asli tidak dituliskan di sini.** Placeholder `<...>` di bawah diisi
> dari berkas `KREDENSIAL.local.md`, yang sengaja tidak ikut masuk repositori.
> Saat memasang di perangkat lain, buat nilai baru — jangan menyalin nilai lama.

### Basis data

| Kunci | Nilai |
|---|---|
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | `vpn_lifecycle` |
| `DB_USERNAME` | `vpn_app` |
| `DB_PASSWORD` | `<DB_PASSWORD>` |

### Antrean

| Kunci | Nilai |
|---|---|
| `QUEUE_CONNECTION` | `database` |

### Admin dashboard (dibuat oleh seeder)

| Kolom | Nilai |
|---|---|
| Email | `admin@vpn.local` |
| Password | `<ADMIN_PASSWORD>` |

### MikroTik RouterOS

| Kunci | Nilai sementara |
|---|---|
| `ROUTEROS_BASE_URL` | `https://192.168.56.2` |
| `ROUTEROS_USER` | `api-laravel` |
| `ROUTEROS_PASSWORD` | `<ROUTEROS_PASSWORD>` |
| `ROUTEROS_VERIFY_TLS` | `false` |
| `ROUTEROS_TIMEOUT` | `10` |
| IPsec pre-shared key | `<IPSEC_PSK>` |

`ROUTEROS_VERIFY_TLS=false` karena CHR memakai sertifikat swasembada di
lingkungan lab. Wajib dijelaskan di batasan laporan.

---

## 7. Hasil migration

14 migration berhasil (3 bawaan Laravel + 11 skema sistem), total 18 tabel.

```
0001_01_01_000000_create_users_table
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
2026_09_05_120001_create_vps_table
2026_09_05_120002_create_paket_bandwidth_table
2026_09_05_120003_create_pengajuan_table
2026_09_05_120004_create_akun_vpn_table
2026_09_05_120005_add_akun_vpn_foreign_to_pengajuan
2026_09_05_120006_create_vps_health_checks_table
2026_09_05_120007_create_sesi_vpn_table
2026_09_05_120008_create_operasi_router_table
2026_09_05_120009_create_drift_table
2026_09_05_120010_create_audit_log_table
2026_09_05_120011_ubah_nama_vps_jadi_index_biasa
```

---

## 8. Verifikasi integritas skema

Memastikan aturan penghapusan VPS ditegakkan basis data, bukan sekadar
tertulis di dokumen (lihat CLAUDE.md 4.4b).

```sql
SELECT k.TABLE_NAME, k.COLUMN_NAME, rc.DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
JOIN information_schema.KEY_COLUMN_USAGE k
  ON k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 AND k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE rc.CONSTRAINT_SCHEMA = 'vpn_lifecycle'
  AND k.REFERENCED_TABLE_NAME = 'vps';
```

Hasil yang benar:

| Tabel | Kolom | Aturan hapus | Alasan |
|---|---|---|---|
| `akun_vpn` | `vps_id` | **RESTRICT** | basis data menolak hapus VPS yang masih punya akun |
| `pengajuan` | `vps_id` | **RESTRICT** | idem, untuk pengajuan |
| `vps_health_checks` | `vps_id` | CASCADE | telemetri murni, tidak punya objek pasangan di router |
| `drift` | `vps_id` | SET NULL | temuan tetap disimpan sebagai jejak |
| `operasi_router` | `vps_id` | SET NULL | riwayat operasi tetap disimpan untuk data Bab 4 |

Dua `RESTRICT` itu disengaja: penghapusan VPS wajib melewati job berantai yang
membersihkan objek di MikroTik lebih dulu. Bila ada kode yang mencoba
menghapus VPS langsung, ia gagal di tingkat basis data — bukan diam-diam
meninggalkan objek yatim di router.

---

## 9. Reproduksi di perangkat lain

```bash
# 1. Prasyarat
sudo apt install -y php php-cli php-mysql php-mbstring php-xml php-curl \
                    php-zip composer mariadb-server nodejs npm
sudo systemctl enable --now mariadb

# 2. Basis data + pengguna  (ganti <PASSWORD>)
sudo mysql -e "CREATE DATABASE IF NOT EXISTS vpn_lifecycle
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "
CREATE USER IF NOT EXISTS 'vpn_app'@'localhost' IDENTIFIED BY '<PASSWORD>';
CREATE USER IF NOT EXISTS 'vpn_app'@'127.0.0.1' IDENTIFIED BY '<PASSWORD>';
GRANT ALL PRIVILEGES ON vpn_lifecycle.* TO 'vpn_app'@'localhost';
GRANT ALL PRIVILEGES ON vpn_lifecycle.* TO 'vpn_app'@'127.0.0.1';
FLUSH PRIVILEGES;"

# 3. Backend
cd vpn-backend
composer install
cp .env.example .env          # lalu isi nilai bagian 6
php artisan key:generate
php artisan migrate
php artisan db:seed

# 4. Frontend
cd ../vpn-frontend
npm install
npm run dev
```

---

## 12. Penyiapan MikroTik CHR di VirtualBox

### 12.1 Topologi lab

```
                       bridged (LAN)
Laravel (host) ──host-only 192.168.56.0/24──► CHR ──internal "VPS Network" 10.10.10.0/24──► VPS
                     (REST API :443)         .2 │ .1
                                        klien VPN 10.10.20.0/24 (lewat bridged LAN)
```

| Jaringan | Rentang | Fungsi | Interface CHR |
|---|---|---|---|
| Bridged (LAN) | ikut LAN fisik | klien VPN terhubung ke sini + jalur internet keluar | `ether1` |
| Host-only (`vboxnet0`) | `192.168.56.0/24` | Laravel ↔ REST API RouterOS. CHR di `.2`, host di `.1` | `ether2` |
| Internal `VPS Network` | `10.10.10.0/24` | tempat VPS berada. CHR jadi gateway di `.1` | `ether3` |
| Pool VPN | `10.10.20.0/24` | alamat yang dibagikan ke klien VPN | — |

### 12.2 Adapter VirtualBox pada VM CHR

| Adapter | Mode | Keterangan |
|---|---|---|
| 1 | Bridged Adapter | LAN fisik: klien VPN terhubung ke sini, dan jalur internet klien keluar lewat sini |
| 2 | Host-only Adapter (`vboxnet0`) | jalur manajemen REST API; dipakai Laravel |
| 3 | Internal Network, nama `VPS Network` | jaringan VPS |

Bila `vboxnet0` belum ada: **File → Tools → Network Manager → Create**, biarkan
`192.168.56.1/24`, DHCP server boleh dimatikan.

### 12.2b Impor OVA dan boot pertama

1. **Impor**: File → Import Appliance → pilih OVA CHR → Import. RAM dan CPU
   default sudah cukup untuk lab.

2. **Adapter**: pastikan ketiganya terpasang sesuai tabel 12.2. Urutan adapter
   menentukan penamaan di RouterOS: Adapter 1 → `ether1`, 2 → `ether2`,
   3 → `ether3`.

3. **Cek rentang host-only**: File → Tools → Network Manager, pastikan
   `vboxnet0` beralamat `192.168.56.1/24` (host di `.1`). Bila berbeda,
   sesuaikan alamat CHR dan `ROUTEROS_BASE_URL` di `.env`.

4. **Boot & login pertama** di jendela konsol VirtualBox:
   - Login `admin`, password kosong (langsung Enter)
   - Muncul persetujuan lisensi RouterOS → ketik `y`
   - RouterOS 7 meminta password baru → isi dan **catat**. Ini password
     konsol `admin`, berbeda dari password user API di bagian 6.

5. **Alamat manajemen** — ketik manual, hanya satu baris (host-only = `ether2`):

   ```routeros
   /ip address add address=192.168.56.2/24 interface=ether2
   ```

   > Konsol VirtualBox tidak mendukung tempel teks. Jangan menempel skrip
   > panjang di sini; cukup satu baris di atas, lalu lanjutkan lewat SSH.

6. **Pindah ke SSH** dari terminal host — di sini tempel teks berfungsi:

   ```bash
   ping -c 3 192.168.56.2
   ssh admin@192.168.56.2
   ```

7. **Internet untuk lisensi** (via adapter bridged `ether1`):

   ```routeros
   /ip dhcp-client add interface=ether1 disabled=no
   /ping 8.8.8.8 count=3
   ```

   Bila LAN tanpa DHCP, pasang alamat statis dan rute default:

   ```routeros
   /ip address add address=192.168.1.x/24 interface=ether1
   /ip route add dst-address=0.0.0.0/0 gateway=192.168.1.1
   ```

8. **Trial lisensi p-unlimited 60 hari** — wajib bila ingin menguji perbedaan
   paket bandwidth. Lisensi gratis CHR mengunci throughput di 1 Mbps sehingga
   paket 2M, 5M, dan 10M akan terlihat sama.

   ```routeros
   /system license print
   ```

   Perpanjangan trial dilakukan lewat akun mikrotik.com pada menu
   `/system license renew`. Bila trial tidak dipakai, catat di batasan laporan
   bahwa pengujian bandwidth dilakukan di bawah 1 Mbps.

9. Lanjut ke skrip konfigurasi 12.3.

### 12.3 Konfigurasi RouterOS

Seluruh konfigurasi ada di **`chr-setup.rsc`** (folder induk). Jangan menyalin
ulang perintah di sini secara manual; impor berkasnya supaya hasilnya identik
setiap kali. Langkah impor ada di **12.5b**, penjelasan baris demi baris ada
di **12b**.

Isi berkas, ringkas, dengan pemetaan interface sesuai 12.2:

| Langkah | Objek | Interface |
|---|---|---|
| Alamat `10.10.10.1/24` (gateway VPS Network) | `/ip address` | `ether3` |
| Sertifikat `api-cert` + `www-ssl:443` | REST API | `ether2` (host-only) |
| User `api-laravel` grup `api-lifecycle` | akses Laravel | — |
| Pool `vpn-pool` `10.10.20.10-250` | alamat klien VPN | — |
| Profile `vpn-dasar` / `vpn-standar` / `vpn-prioritas` | 2M / 5M / 10M | — |
| L2TP server `use-ipsec=required` | layanan VPN | — |
| Firewall `input`: UDP 500,1701,4500 + `ipsec-esp` accept | klien boleh masuk | — |
| Firewall `forward`: 2 aturan `drop` (isolasi antar klien + tolak default) | INTI keputusan #3 | — |
| NAT `srcnat` masquerade `10.10.20.0/24` | internet klien keluar | `out-interface=ether1` |
| DNS `8.8.8.8,1.1.1.1` + `dns-server` pada tiap profile | resolusi nama klien | — |

Alamat manajemen `192.168.56.2/24` pada `ether2` **tidak** dibuat skrip; sudah
dipasang manual di 12.2b langkah 5, sebab skrip dikirim lewat jaringan itu.

> **Aturan `drop` default pada `forward` adalah kunci.** Dipasang sekali di
> awal; setiap akun yang di-provision menambahkan aturan `accept` spesifik
> (src = `ip_vpn` akun, dst = alamat VPS yang disetujui) yang disisipkan
> **di atas** aturan drop. Akun tanpa aturan accept otomatis tidak bisa
> menjangkau VPS mana pun.

### 12.4 Isi `.env` Laravel

```
ROUTEROS_BASE_URL=https://192.168.56.2
ROUTEROS_USER=api-laravel
ROUTEROS_PASSWORD=<ROUTEROS_PASSWORD>
ROUTEROS_VERIFY_TLS=false
ROUTEROS_TIMEOUT=10

# Harus sama dengan <IPSEC_PSK> di chr-setup.rsc langkah 7
ROUTEROS_IPSEC_PSK=<IPSEC_PSK>
# Alamat CHR dari sudut pandang KLIEN L2TP (IP bridged/LAN ether1), BUKAN 192.168.56.2
ROUTEROS_VPN_SERVER=<IP_LAN_CHR>
# Harus sama dengan /ip pool di router
ROUTEROS_POOL_RANGE=10.10.20.10-10.10.20.250

# Sisanya punya nilai bawaan benar di config/routeros.php, isi hanya bila beda:
# ROUTEROS_IP_POOL=vpn-pool
# ROUTEROS_ADDRESS_LIST=vpn-klien
# ROUTEROS_LOCAL_ADDRESS=10.10.20.1
```

### 12.5 Verifikasi

```bash
php artisan router:cek
php artisan router:cek --ping=10.10.10.1
```

Perintah ini memeriksa koneksi, versi RouterOS, keberadaan IP pool, ketiga PPP
profile, status L2TP server, dan keterbacaan menu yang akan ditulis sistem.
Semua harus `OK` sebelum fitur provisioning dipakai.

### 12.5b Cara cepat: impor berkas konfigurasi

Berkas `chr-setup.rsc` di folder induk berisi seluruh konfigurasi bagian 12.3
dan dapat langsung diimpor, sehingga hasilnya identik setiap kali diulang.
Isi dulu dua placeholder (`<ROUTEROS_PASSWORD>`, `<IPSEC_PSK>`) dengan nilai
yang sama seperti `.env`:

```bash
sed -e "s|<ROUTEROS_PASSWORD>|nilai|" -e "s|<IPSEC_PSK>|nilai|" \
    chr-setup.rsc > chr-setup.local.rsc
scp chr-setup.local.rsc admin@192.168.56.2:chr-setup.rsc
ssh admin@192.168.56.2 "/import file=chr-setup.rsc"
```

Skrip mencetak progres 1/11 sampai 11/11. Terdapat jeda 15 detik yang disengaja
setelah pembuatan sertifikat: penandatanganan sertifikat di RouterOS berjalan
asinkron, dan bila `www-ssl` dikonfigurasi sebelum sertifikat selesai, service
gagal aktif tanpa pesan galat yang jelas.

### 12.5c Hasil verifikasi (2026-09-05)

`php artisan router:cek` terhadap CHR **RouterOS 7.23.5 (long-term)** —
sembilan prasyarat lolos: IP pool, tiga PPP profile, L2TP server, dan
keterbacaan menu `ppp/secret`, `address-list`, `firewall/filter`, `ppp/active`.

Uji tulis-baca-hapus langsung ke router juga lolos:

| Operasi | Hasil |
|---|---|
| Buat `ppp/secret` | berhasil, RouterOS mengembalikan `.id = *1` |
| Baca kembali | name, profile, remote-address, comment sesuai |
| Ubah profile | `vpn-dasar` → `vpn-prioritas` |
| Disable | `disabled = true` |
| Hapus | sisa di router = 0 |
| Ping dari router | `status=up rtt=0.06ms loss=0%` |

Artinya seluruh operasi siklus hidup yang dibutuhkan sistem sudah terbukti
dapat dijalankan lewat REST API.

### 12.6 Penyiapan VM VPS (Alpine Linux)

Dua VM, masing-masing satu VPS. Dua diperlukan, bukan satu: dengan satu VPS
hanya bisa dibuktikan "akses berhasil"; dengan dua bisa dibuktikan akun yang
disetujui untuk VPS-A **tidak dapat** menjangkau VPS-B meski keduanya berada
di jaringan yang sama.

| VM | Hostname | Alamat |
|---|---|---|
| VPS-APP-01 | `vps-app-01` | `10.10.10.11/24` |
| VPS-DB-02 | `vps-db-02` | `10.10.10.12/24` |

> **JANGAN mengambil jalan pintas** dengan menambahkan `10.10.10.11` sebagai
> alamat sekunder pada MikroTik. Ping-nya memang akan berhasil, tetapi trafik
> menuju alamat milik router sendiri masuk ke chain `input`, sedangkan seluruh
> aturan isolasi berada di chain `forward`. Aturan tersebut tidak akan pernah
> tersentuh, sehingga demonstrasi isolasi menjadi palsu tanpa terlihat palsu.

Tiga jalur mungkin. **Jalur A yang dipakai** karena OVA-nya sudah ada dan
penyiapannya paling singkat.

| | **A — CHR (dipakai)** | B — TurnKey Core OVA | C — Alpine dari ISO |
|---|---|---|---|
| Unduhan | **0 MB** (OVA sudah ada) | ~500 MB | ~60 MB |
| Penyiapan | 3 baris perintah | wizard + atur IP | `setup-alpine` ~5 menit |
| RAM per VM | 192 MB | 512 MB–1 GB | 256 MB |
| Bukti akses | Webfig di port 80 | panel web port 12321 | perlu pasang httpd |

Jalur A memakai RouterOS sebagai **host tiruan**, bukan sebagai router. Ini sah
untuk pengujian karena sistem memang tidak melakukan manajemen apa pun ke dalam
VPS (batasan #5) — VPS hanya berperan sebagai target beralamat IP yang membalas
ICMP dan melayani satu port. Yang diuji adalah apakah router menegakkan isolasi
antar target, bukan apa yang berjalan di dalam targetnya.

Tuliskan pilihan ini di laporan sebagai keterbatasan lingkungan uji: VPS
disimulasikan dengan mesin virtual RouterOS, bukan sistem operasi server.

---

### Jalur A — CHR sebagai VPS tiruan

#### a. Impor OVA dua kali

File → Import Appliance → pilih OVA CHR yang sama seperti sebelumnya.
Lakukan dua kali, beri nama `VPS-APP-01` dan `VPS-DB-02`.

> **WAJIB: centang "Generate new MAC addresses for all network adapters"**
> pada dialog impor.
>
> Tanpa itu, ketiga mesin (gateway + dua VPS) berbagi MAC yang sama. Pada satu
> segmen jaringan, salah satunya menjadi tidak terjangkau — dan gejalanya
> sangat menyesatkan: ping ke `10.10.10.12` gagal, sehingga tampak seolah
> aturan isolasi bekerja, padahal yang terjadi hanyalah tabrakan MAC. Ini bisa
> membuat kesimpulan bab pengujian keliru.

#### b. Adapter tiap VM VPS

Hanya **satu** adapter. VPS tidak perlu jalur manajemen maupun internet.

| Adapter | Mode | Nama |
|---|---|---|
| 1 | Internal Network | `VPS Network` |

Adapter 2 dan 3 dimatikan. Memori dapat diturunkan ke 192 MB.

#### c. Konfigurasi (ketik di konsol VirtualBox)

Boot, login `admin` tanpa kata sandi, ketik `y` pada lisensi, lalu isi kata
sandi baru saat diminta.

**VPS-APP-01:**

```routeros
/system identity set name=VPS-APP-01
/ip address add address=10.10.10.11/24 interface=ether1
/ip route add dst-address=0.0.0.0/0 gateway=10.10.10.1
```

**VPS-DB-02:**

```routeros
/system identity set name=VPS-DB-02
/ip address add address=10.10.10.12/24 interface=ether1
/ip route add dst-address=0.0.0.0/0 gateway=10.10.10.1
```

Baris ketiga adalah **rute balik yang wajib**. Tanpa rute default menuju
`10.10.10.1`, paket dari klien VPN (`10.10.20.0/24`) memang sampai ke VPS,
tetapi balasannya tidak tahu jalan pulang. Gejalanya menipu: koneksi VPN
tersambung dan aturan firewall benar, namun halaman tidak pernah terbuka —
dan kesalahan akan dicari di firewall, bukan di sisi VPS.

`/system identity` bukan sekadar kosmetik: nama itu tampil di halaman Webfig,
sehingga tangkapan layar bukti akses langsung menunjukkan VPS mana yang
sedang dibuka.

#### d. Bukti akses

RouterOS sudah menyalakan layanan `www` pada port 80, jadi tidak ada yang
perlu dipasang. Membuka `http://10.10.10.11` dari klien VPN akan menampilkan
halaman Webfig bertuliskan **VPS-APP-01**.

Lanjut ke bagian **f** (verifikasi dari router) dan seterusnya di bawah.

---

### Jalur B — TurnKey Core OVA

Debian minimal yang didistribusikan dalam format OVA. Unduh dari
https://www.turnkeylinux.org/core (pilih format VM/OVA), impor dua kali
dengan MAC baru, adapter 1 ke `VPS Network` dan adapter 2 NAT, lalu setel alamat
statis di `/etc/network/interfaces`:

```
auto eth0
iface eth0 inet static
        address 10.10.10.11
        netmask 255.255.255.0
        up ip route add 10.10.20.0/24 via 10.10.10.1
```

Panel webnya di port 12321 dapat dipakai sebagai bukti akses.

---

### Jalur C — Alpine dari ISO

#### a. Unduh ISO

`alpine-virt-<versi>-x86_64.iso` dari https://alpinelinux.org/downloads/
(varian **virt**, sekitar 60 MB, memang dioptimalkan untuk mesin virtual).

#### b. Buat VM di VirtualBox

Ulangi untuk kedua VM:

| Pengaturan | Nilai |
|---|---|
| Type / Version | Linux / Other Linux (64-bit) |
| Memory | 512 MB |
| Disk | 2 GB |
| Adapter 1 | **Internal Network**, nama `VPS Network` |
| Adapter 2 | **NAT** (hanya untuk mengunduh paket saat instalasi) |
| Optical | ISO Alpine |

Adapter 1 menjadi `eth0`, Adapter 2 menjadi `eth1`.

#### c. Instalasi

Boot, login sebagai `root` tanpa kata sandi, lalu jalankan:

```sh
setup-alpine
```

Jawaban yang penting:

| Pertanyaan | Jawaban |
|---|---|
| Keyboard layout | `us` lalu `us` |
| Hostname | `vps-app-01` (atau `vps-db-02`) |
| Interface | `eth0` |
| Ip address for eth0 | `10.10.10.11` (atau `.12`) |
| Netmask | `255.255.255.0` |
| **Gateway** | **`none`** |
| Interface berikutnya | `eth1` |
| Ip address for eth1 | `dhcp` |
| Interface berikutnya | `done` |
| Manual network configuration | `no` |
| Root password | isi dan catat |
| Timezone | `Asia/Jakarta` |
| Proxy | `none` |
| APK mirror | pilih nomor mana pun (lewat eth1) |
| SSH server | `openssh` |
| Disk | `sda`, mode `sys` |

Setelah selesai: `poweroff`, lepas ISO dari VM, nyalakan kembali.

#### d. Rute balik ke klien VPN — WAJIB

`eth0` sengaja tidak diberi gateway supaya rute default tetap lewat `eth1`
(internet). Konsekuensinya, balasan menuju klien VPN di `10.10.20.0/24` akan
ikut keluar lewat `eth1` dan hilang — koneksi tampak "tersambung tapi tidak
ada balasan".

Tambahkan rute statis yang bertahan setelah reboot. Edit
`/etc/network/interfaces`, pada blok `eth0` tambahkan baris `up`:

```
iface eth0 inet static
        address 10.10.10.11
        netmask 255.255.255.0
        up ip route add 10.10.20.0/24 via 10.10.10.1
```

Terapkan:

```sh
rc-service networking restart
ip route          # harus memuat: 10.10.20.0/24 via 10.10.10.1 dev eth0
```

#### e. Layanan bukti akses

Supaya keberhasilan akses terlihat kasat mata di laporan, jalankan web server
kecil bawaan BusyBox:

```sh
mkdir -p /www
echo "<h1>VPS-APP-01</h1><p>Server aplikasi internal.</p>" > /www/index.html
busybox httpd -p 80 -h /www

# agar otomatis jalan saat boot
echo 'busybox httpd -p 80 -h /www' >> /etc/local.d/web.start
chmod +x /etc/local.d/web.start
rc-update add local
```

Ulangi untuk `vps-db-02` dengan isi halaman yang berbeda.

#### f. Verifikasi dari router

```bash
php artisan router:cek --ping=10.10.10.11
php artisan router:cek --ping=10.10.10.12
```

Keduanya harus `status=up`.

#### g. Daftarkan lewat dashboard

Masuk sebagai admin, buka **Daftar VPS**, tambahkan keduanya, lalu tekan
**Ping** pada masing-masing baris.

#### h. Uji isolasi — inti pembuktian keamanan

1. Ajukan dan setujui satu akun untuk **VPS-APP-01** saja.
2. Sambungkan klien L2TP dari HP atau laptop ke `192.168.56.2` memakai
   kredensial dari halaman detail akun.
3. Dari perangkat itu:

   | Uji | Hasil yang diharapkan |
   |---|---|
   | `http://10.10.10.11` | **berhasil** — halaman VPS-APP-01 tampil |
   | `http://10.10.10.12` | **gagal / timeout** — tidak disetujui |
   | `ping 10.10.10.12` | **100% loss** |

4. Kembali ke dashboard, buka detail akun: **riwayat sesi** harus memuat satu
   baris dengan waktu mulai, alamat asal, dan volume data.

Poin 3 adalah bukti bahwa isolasi per-VPS benar-benar ditegakkan router, bukan
sekadar catatan administratif — dan poin 4 membuktikan log sesi berjalan.

#### i. Kebutuhan memori

CHR 256 MB + dua VM Alpine 512 MB = sekitar 1,3 GB. Setelah instalasi selesai,
memori tiap VM Alpine dapat diturunkan ke 256 MB.

---

## 12b. Anatomi konfigurasi router: apa yang disetel dan mengapa

Bagian ini menjelaskan isi `chr-setup.rsc` baris demi baris. Ditulis supaya
konfigurasi dapat dipertanggungjawabkan saat sidang, bukan sekadar disalin.

### 12b.1 Tiga lapisan konfigurasi

| Lapisan | Dikerjakan oleh | Frekuensi |
|---|---|---|
| Alamat interface manajemen | Manual di konsol | sekali, sebelum skrip |
| Fondasi layanan VPN | `chr-setup.rsc` | sekali per router |
| Objek milik tiap akun | Sistem, otomatis | tiap pengajuan disetujui |

Pemisahan ini adalah dasar Batasan Masalah #3: sistem mengelola **siklus hidup
akun**, bukan memasang router dari nol.

### 12b.2 Lapisan 1 — manual sebelum skrip

```routeros
/ip address add address=192.168.56.2/24 interface=ether2
```

Hanya satu baris, pada interface host-only (`ether2`). Skrip dikirim lewat
jaringan itu, sehingga router harus sudah dapat dihubungi sebelum skrip masuk.

Konsol VirtualBox tidak mendukung tempel teks, dan mengetik seluruh skrip
secara manual membuka peluang salah ketik yang besar. Karena itu polanya:
satu baris di konsol, sisanya lewat SSH.

### 12b.3 Lapisan 2 — isi skrip

#### Langkah 1: alamat jaringan VPS

```routeros
/ip address add address=10.10.10.1/24 interface=ether3
```

Router menjadi gerbang bagi jaringan internal `VPS Network` tempat VPS berada
(`ether3`).

> Bila dilewat: VPS tidak memiliki gateway, dan aturan isolasi pada chain
> `forward` tidak pernah dilalui trafik apa pun karena tidak ada jalur menuju
> VPS.

#### Langkah 2 sampai 4: jalur kendali Laravel

```routeros
/certificate add name=api-cert common-name=chr.lab key-size=2048 days-valid=3650
sign api-cert
:delay 15s
/ip service set www-ssl certificate=api-cert disabled=no port=443
/user group add name=api-lifecycle policy=read,write,api,rest-api,test,winbox,password
/user add name=api-laravel group=api-lifecycle password=<ROUTEROS_PASSWORD>
```

Tiga langkah ini yang memberi sistem kemampuan menyentuh router. REST API
RouterOS v7 berjalan di atas `www-ssl`, dan `www-ssl` menolak menyala tanpa
sertifikat. Sertifikat swasembada memadai untuk laboratorium, dan itulah
alasan `ROUTEROS_VERIFY_TLS=false` pada `.env`.

> **Jeda 15 detik bukan hiasan.** Penandatanganan sertifikat berjalan
> asinkron. Bila `www-ssl` dikonfigurasi sebelum sertifikat selesai, service
> gagal menyala **tanpa pesan galat yang jelas**; REST API kemudian menolak
> koneksi dan penyebabnya sulit ditebak.

Pengguna `api-laravel` sengaja dipisahkan dari `admin` agar jejak pada log
router dapat dibedakan: mana yang dilakukan sistem, mana yang dilakukan
manusia lewat Winbox. Pembedaan ini langsung berguna bagi deteksi drift.

> Bila dilewat: `php artisan router:cek` gagal tersambung dan seluruh sistem
> tidak berfungsi.

#### Langkah 5 dan 6: bahan yang dirujuk saat provisioning

```routeros
/ip pool add name=vpn-pool ranges=10.10.20.10-10.10.20.250
/ppp profile add name=vpn-dasar local-address=10.10.20.1 remote-address=vpn-pool \
    rate-limit=2M/2M use-encryption=yes change-tcp-mss=yes
```

Rentang pool **harus sama** dengan `ROUTEROS_POOL_RANGE` pada `.env`, karena
pemilihan alamat dilakukan oleh `AlokasiIp` di Laravel, bukan oleh router.

Nama profile **harus sama persis** dengan kolom `ppp_profile` pada tabel
`paket_bandwidth`; keduanya dicocokkan berdasarkan nama. Sejak menu Pengaturan
tersedia, profile ini dapat dibuat dan diperbarui dari web.

`change-tcp-mss=yes` mencegah gejala klasik terowongan: halaman web terbuka
separuh lalu menggantung karena ukuran paket melebihi kapasitas terowongan.

> Bila dilewat: provisioning gagal dengan pesan profile tidak ditemukan.

#### Langkah 7: layanan VPN

```routeros
/interface l2tp-server server
set enabled=yes use-ipsec=required ipsec-secret=<IPSEC_PSK> \
    default-profile=vpn-standar authentication=mschap2
```

Nilai `required`, bukan `yes`. Perbedaannya nyata: `yes` masih mengizinkan
klien tanpa enkripsi terhubung, sedangkan `required` menolaknya. Sistem yang
mengklaim mengamankan akses tidak boleh membiarkan terowongan tanpa enkripsi.

#### Langkah 8: mengizinkan klien masuk

```routeros
/ip firewall filter
add chain=input protocol=udp port=500,1701,4500 action=accept
add chain=input protocol=ipsec-esp action=accept
```

Chain `input` mengatur trafik yang menuju router itu sendiri. UDP 500 untuk
negosiasi kunci, 4500 untuk melewati NAT, 1701 untuk L2TP di dalam terowongan.

> Bila dilewat: klien menggantung pada status menyambung selamanya, dan log
> router kosong sama sekali karena paketnya tidak pernah diterima.

#### Langkah 9: fondasi isolasi

Bagian terpenting dari seluruh skrip.

```routeros
/ip firewall filter
add chain=forward src-address=10.10.20.0/24 dst-address=10.10.20.0/24 action=drop \
    comment="vpnlc:sistem - isolasi antar klien"
add chain=forward src-address=10.10.20.0/24 dst-address=10.10.10.0/24 action=drop \
    comment="vpnlc:sistem - tolak default"
```

Aturan pertama melarang klien VPN saling menjangkau. Aturan kedua menolak
seluruh akses ke jaringan VPS secara bawaan.

Setiap akun yang di-provision kemudian menyisipkan satu aturan `accept`
**tepat di atas** aturan tolak tersebut:

```
drop    10.10.20.0/24 -> 10.10.20.0/24     isolasi antar klien
accept  10.10.20.14   -> vpnlc-akun-8     disisipkan sistem
drop    10.10.20.0/24 -> 10.10.10.0/24    tolak default
```

RouterOS membaca aturan dari atas ke bawah dan berhenti pada yang pertama
cocok. Akun yang memiliki aturan accept lolos; yang tidak memilikinya jatuh ke
aturan drop.

> **Konsekuensinya sistem gagal ke arah aman.** Bila provisioning gagal
> separuh jalan atau aturan accept terhapus, akun kehilangan akses, bukan
> memperoleh akses ke seluruh jaringan. Inilah yang membuat klaim keamanan
> penelitian ini dapat dipertahankan.
>
> Karena itu pula aturan ini **sengaja tidak dapat diubah dari web**.
> Menjadikannya dapat disunting berarti mengubah jaminan menjadi asumsi.

#### Langkah 10 dan 11: klien tetap dapat internet

```routeros
/ip firewall nat
add chain=srcnat src-address=10.10.20.0/24 out-interface=ether1 action=masquerade
/ip dns
set servers=8.8.8.8,1.1.1.1 allow-remote-requests=yes
/ppp profile set [find name~"^vpn-"] dns-server=10.10.20.1
```

Trafik klien menuju internet sebenarnya sudah lolos chain `forward`. Yang
hilang adalah NAT: paket keluar membawa alamat asal `10.10.20.x` yang tidak
dikenal internet, sehingga balasannya tidak tahu jalan pulang. Gejalanya
tampak seperti VPN memutus internet, padahal firewall tidak menolak apa pun.
`out-interface=ether1` = interface bridged yang menuju LAN dan internet.

DNS adalah persoalan terpisah. Tanpa `dns-server` pada profile, klien dapat
membuka alamat IP tetapi tidak dapat membuka nama domain apa pun.

### 12b.4 Lapisan 3 — objek yang dibuat sistem

Setiap pengajuan yang disetujui menghasilkan tiga objek:

| Objek | Isi |
|---|---|
| `/ppp secret` | username, password, profile sesuai paket, `remote-address` tetap |
| `/ip firewall address-list` | daftar `vpnlc-akun-N` berisi alamat VPS yang disetujui |
| `/ip firewall filter` | aturan accept, disisipkan di atas aturan tolak default |

Ketiganya diberi comment `vpnlc:akun:N`. Penanda inilah yang dipakai deteksi
drift untuk membedakan objek buatan sistem dari objek yang dibuat manual lewat
Winbox.

### 12b.5 Menyiapkan MikroTik baru

1. Manual di konsol (interface host-only):

   ```routeros
   /ip address add address=192.168.56.2/24 interface=ether2
   ```

2. Isi placeholder, kirim, dan impor:

   ```bash
   sed -e "s|<ROUTEROS_PASSWORD>|nilai|" -e "s|<IPSEC_PSK>|nilai|" \
       chr-setup.rsc > chr-setup.local.rsc
   scp chr-setup.local.rsc admin@192.168.56.2:chr-setup.rsc
   ssh admin@192.168.56.2 "/import file=chr-setup.rsc"
   ```

3. Yang wajib disesuaikan sebelum impor:

   | Bagian | Sesuaikan bila |
   |---|---|
   | `<ROUTEROS_PASSWORD>` | selalu; harus sama dengan `.env` |
   | `<IPSEC_PSK>` | selalu; harus sama dengan `.env` |
   | `ether3` (alamat VPS Network), `ether1` (masquerade) | urutan adapter VirtualBox berbeda dari 12.2 |
   | `10.10.10.0/24` | jaringan VPS memakai rentang lain |
   | `10.10.20.0/24` dan pool | harus sama dengan `ROUTEROS_POOL_RANGE` |

4. Verifikasi:

   ```bash
   php artisan router:cek
   ```

   Seluruh prasyarat harus `OK` sebelum fitur apa pun dipakai. Perintah ini
   memang dibuat untuk memastikan lapisan 2 lengkap sebelum lapisan 3 berjalan.

---

## 13. Menjalankan aplikasi

### Catatan perbaikan proses (2026-09-05)

Port 8000 sebelumnya dipakai proses Laravel proyek ini yang masih hidup,
tetapi hanya mendengarkan pada `127.0.0.1`. Frontend memakai API LAN
`http://192.168.1.52:8000/api`, sehingga backend harus memakai `--host=0.0.0.0`.
Tiga proses Vite yang bertumpuk telah dirapikan menjadi satu pada port 5173;
queue worker disisakan satu, dan scheduler diaktifkan.

Backend, frontend, dan scheduler saat perbaikan dijalankan di background.
Log dan PID proses peluncurnya tersedia di `.run/` pada folder induk proyek.
Proses ini tidak otomatis hidup setelah komputer reboot. Queue worker yang
sudah berjalan tetap dipakai. Jangan menjalankan server kedua saat portnya
masih dipakai; cek terlebih dahulu dengan `ss -ltnp | rg ':8000|:5173'`.

Alamat untuk pengecekan saat ini:
- Publik: `http://192.168.1.52:5173/vps-tersedia`
- Admin: `http://192.168.1.52:5173/admin/login`

Saat menjalankan frontend manual, gunakan
`npm run dev -- --host 0.0.0.0 --port 5173 --strictPort`
agar Vite tidak diam-diam pindah ke port 5174/5175 yang tidak diizinkan CORS.
Verifikasi perbaikan: halaman publik/login dan API VPS merespons HTTP 200,
CORS origin LAN sesuai, seluruh migration sudah berjalan, `router:cek` lolos,
build frontend berhasil, dan 18 tes backend lulus (45 assertions).

Empat proses, masing-masing di terminal sendiri. Perhatikan `--host`: tanpa itu
server hanya mendengarkan di `127.0.0.1` dan tidak dapat dibuka dari PC lain.

```bash
# 1. Backend API
cd vpn-backend && php artisan serve --host=0.0.0.0 --port=8000

# 2. Queue worker (WAJIB) — memproses provisioning ke router
cd vpn-backend && php artisan queue:work

# 3. Penjadwal — log sesi, ping VPS, deteksi drift, kedaluwarsa
cd vpn-backend && php artisan schedule:work

# 4. Frontend
cd vpn-frontend && npm run dev -- --host 0.0.0.0
```

Tanpa proses 2, pengajuan yang disetujui berhenti di status
`menunggu_provision` dan akun tidak pernah dibuat di router.
Tanpa proses 3, log sesi tidak terisi dan kedaluwarsa tidak berjalan otomatis.

### 13.1 Alamat aplikasi

Ganti `<IP_KALI>` dengan alamat LAN mesin yang menjalankan Laravel.

| Halaman | Alamat |
|---|---|
| Publik — daftar VPS | `http://<IP_KALI>:5173/vps-tersedia` |
| Publik — form pengajuan | `http://<IP_KALI>:5173/pengajuan-vpn` |
| Publik — cek status & perpanjangan | `http://<IP_KALI>:5173/cek-status` |
| Dashboard admin | `http://<IP_KALI>:5173/admin/login` |

### 13.2 Konfigurasi agar dapat diakses PC lain

| Berkas | Kunci | Nilai |
|---|---|---|
| `vpn-frontend/.env` | `VITE_API_BASE_URL` | `http://<IP_KALI>:8000/api` |
| `vpn-backend/config/cors.php` | `allowed_origins_patterns` | pola LAN, mis. `#^http://192\.168\.1\.\d{1,3}:5173$#` |

> `VITE_API_BASE_URL` dipanggang saat build, jadi setiap kali alamat berubah
> nilainya harus disunting lalu `npm run dev` dijalankan ulang.
>
> CORS sengaja memakai **pola**, bukan alamat tetap. Alamat mesin berasal dari
> DHCP; bila dikunci ke satu alamat, suatu saat aplikasi berhenti bekerja
> dengan galat CORS di console browser yang penyebabnya sulit ditebak.

### 13.3 Peran pengguna — apa yang perlu dipasang

| Peran | Perlu salinan proyek? | Perlu basis data? |
|---|---|---|
| Klien VPN (konek L2TP lalu akses VPS) | tidak | tidak |
| Pemohon (mengisi form pengajuan) | tidak, cukup browser | tidak |
| Administrator (dashboard) | tidak, cukup browser | tidak |
| Pengembang lain | ya | ya, miliknya sendiri |

> **Basis data tidak perlu dibagi.** Satu backend melayani banyak browser.
> Menjalankan dua salinan backend terhadap satu basis data justru menimbulkan
> masalah: dua queue worker berebut job yang sama, dan dua penjadwal
> menjalankan proses kedaluwarsa dua kali.

---

## 14. Menghubungkan klien VPN

Klien dapat berupa PC atau ponsel mana pun pada jaringan yang sama dengan
adapter **bridged** CHR.

### 14.1 Pengaturan klien

| Field | Isi |
|---|---|
| VPN type | **L2TP/IPsec with pre-shared key** |
| Server address | alamat LAN CHR (adapter bridged) |
| Pre-shared key | `<IPSEC_PSK>` |
| Username & password | dari halaman detail akun di dashboard |

**Windows:** Settings → Network & Internet → VPN → Add VPN, provider "Windows
(built-in)".
**Android:** Settings → VPN → tambah, Type **L2TP/IPSec PSK**.

> Internet pada perangkat klien kemungkinan mati selama VPN tersambung. Ini
> **normal**: sistem sengaja tidak memberi jalan keluar ke internet bagi klien
> VPN. Bila mengganggu, hilangkan centang "Use default gateway on remote
> network" pada properti adapter VPN.

### 14.2 Uji isolasi — inti pembuktian keamanan

Dengan akun yang hanya disetujui untuk VPS-APP-01:

| Uji dari klien | Hasil yang diharapkan | Membuktikan |
|---|---|---|
| `http://10.10.10.11` | halaman VPS-APP-01 tampil | akses yang disetujui berfungsi |
| `http://10.10.10.12` | timeout | isolasi ditegakkan router |
| `ping 10.10.10.12` | 100% loss | blokir di lapisan jaringan |

Aturan yang bekerja, berurutan pada chain `forward`:

```
drop    10.10.20.0/24 -> 10.10.20.0/24     isolasi antar klien
accept  10.10.20.10   -> vpnlc-akun-4     aturan milik akun ini
drop    10.10.20.0/24 -> 10.10.10.0/24    tolak default
```

Address list `vpnlc-akun-4` hanya berisi `10.10.10.11`. Tujuan lain di
`10.10.10.0/24` jatuh ke aturan `drop` di bawahnya.

### 14.3 Memastikan log sesi terisi

```bash
php artisan vpn:polling-sesi     # saat masih tersambung -> sesi tercatat aktif
php artisan vpn:polling-sesi     # setelah diputus       -> durasi tercatat
```

Atau biarkan `php artisan schedule:work` melakukannya tiap 30 detik.
Hasilnya tampil di dashboard, menu **Akun VPN** → detail → Riwayat sesi.

---

## 14b. Mailpit — menangkap email di lingkungan uji

Email tidak dikirim ke alamat sungguhan. Mailpit menangkap semuanya dan
menampilkannya di antarmuka web, sehingga isi email dapat ditunjukkan langsung
saat sidang.

```bash
docker run -d --name mailpit --restart unless-stopped \
  -p 1025:1025 -p 8025:8025 axllent/mailpit
```

| | |
|---|---|
| Kotak masuk | http://localhost:8025 |
| SMTP | `127.0.0.1:1025` |

Konfigurasi `.env` backend:

```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=noreply@vpn.local
MAIL_FROM_NAME="Layanan VPN"
```

> Tanda kutip pada `MAIL_FROM_NAME` wajib. Nilai `.env` yang mengandung spasi
> tanpa kutip membuat seluruh berkas gagal diurai, dan galatnya
> (`Failed to parse dotenv file`) tidak menyebut baris mana penyebabnya.

Empat email yang dikirim sistem:

| Email | Dipicu |
|---|---|
| Pengajuan diterima | pemohon mengirim form |
| Akun VPN aktif + kredensial | **setelah** provisioning ke router berhasil |
| Pengajuan tidak disetujui | admin menolak, memuat alasan |
| Peringatan kedaluwarsa | `vpn:kedaluwarsa` pada H-3 |

---

## 14c. Klien VPN tetap dapat internet

Dua hal terpisah, keduanya diperlukan:

```routeros
# NAT: tanpa ini paket keluar tetapi balasannya tidak tahu jalan pulang,
# dan gejalanya tampak seperti "VPN memutus internet".
# ether1 = interface bridged yang menuju LAN dan internet.
/ip firewall nat
add chain=srcnat src-address=10.10.20.0/24 out-interface=ether1 action=masquerade

# DNS: tanpa ini klien dapat menjangkau VPS lewat alamat IP,
# tetapi tidak dapat membuka nama domain apa pun.
/ip dns
set servers=8.8.8.8,1.1.1.1 allow-remote-requests=yes
/ppp profile
set [find name~"^vpn-"] dns-server=10.10.20.1
```

Sudah termasuk dalam `chr-setup.rsc` langkah 10 dan 11.

---

## 13c. Pemasangan di mesin dev kedua (Ubuntu, 2026-09-10)

Mesin: Ubuntu 26.04, PHP 8.3.33 (default `php`), MySQL 8.4 (bukan MariaDB),
Node 22. Perbedaan dari lingkungan asli dan cara mengatasinya:

| Hal | Di mesin ini |
|---|---|
| Port 8000 | dipakai container Docker lain, backend pindah ke **8090** |
| `composer.lock` | terkunci ke paket yang butuh PHP 8.4.1, `php` di sini 8.3 -> jalankan `composer update` sekali untuk menurunkan Symfony ke 7.x (Laravel 13 tetap jalan di PHP 8.3) |
| MySQL `root` | `auth_socket`, dibuat user `vpn_app` / db `vpn_lifecycle` lewat `sudo mysql` |
| Mail | `MAIL_MAILER=log` (Mailpit belum dipasang), email masuk `storage/logs/laravel.log` |
| RouterOS | belum ada CHR, `.env` `ROUTEROS_*` dikosongkan. Web + dashboard jalan penuh; provisioning ke router akan `gagal_provision` sampai CHR lab disiapkan |

Alamat:

| Halaman | Alamat |
|---|---|
| Publik | `http://192.168.100.14:5173/vps-tersedia` |
| Admin | `http://192.168.100.14:5173/admin/login` (admin@vpn.local / admin12345) |
| API | `http://192.168.100.14:8090/api` |

`config/cors.php` `allowed_origins_patterns` dilonggarkan ke `#^https?://[^/]+:5173$#`
supaya bisa dibuka dari PC/HP mana pun di LAN. `vpn-frontend/.env`
`VITE_API_BASE_URL` menunjuk `192.168.100.14:8090` (baked saat build; ubah lalu
restart `npm run dev` bila IP berubah). Empat proses dijalankan di background.

### Perubahan kode sesi 2026-09-10

| Area | Perubahan |
|---|---|
| Paket bandwidth | Tidak lagi di-seed dan tidak dikonfigurasi di `chr-setup.rsc`. Ditambahkan admin lewat menu Pengaturan; tiap paket membuat PPP profile-nya di router. L2TP `default-profile` jadi `default-encryption`. Lihat CLAUDE.md 11.4 |
| Alamat server VPN + rentang pool | Pindah ke menu Pengaturan (`alamat_server_vpn`, `rentang_pool_vpn`). `.env` jadi nilai bawaan. Lihat CLAUDE.md 11.9b |
| Login admin | Token Sanctum kedaluwarsa 12 jam (`SANCTUM_TOKEN_EXPIRATION`, menit). Galat jaringan/500 sesaat tidak lagi mem-logout admin (hanya 401). Guard verifikasi `/me` sekali per muat, bukan tiap navigasi. Login menghormati `?lanjut=`. |
| Grafik sinkron + drift | `OperasiRouter.vue` (grafik durasi operasi sinkron) kini tampil di atas tabel drift di halaman Pemeriksaan Router. Tombol "Periksa sekarang" mem-poll status sampai job antrean selesai. |

---

## 14d. Uji coba lengkap — langkah demi langkah

Seluruhnya pada satu jaringan yang sama. Perkiraan waktu 15 menit.

### Persiapan

| Proses | Perintah |
|---|---|
| CHR gateway + 2 VM VPS | menyala di VirtualBox |
| Mailpit | `docker start mailpit` |
| Backend | `cd vpn-backend && php artisan serve --host=0.0.0.0 --port=8000` |
| Queue worker | `cd vpn-backend && php artisan queue:work` |
| Penjadwal | `cd vpn-backend && php artisan schedule:work` |
| Frontend | `cd vpn-frontend && npm run dev -- --host 0.0.0.0` |

Periksa kesiapan router lebih dulu:

```bash
php artisan router:cek
php artisan router:cek --ping=10.10.10.11
php artisan router:cek --ping=10.10.10.12
```

Semua prasyarat harus `OK` dan kedua VPS `status=up`.

### Langkah 1 — Admin menyiapkan VPS

1. Buka `http://<IP_KALI>:5173/admin/login`, masuk sebagai admin.
2. Menu **Daftar VPS**. Pastikan `VPS-APP-01` (`10.10.10.11`) dan
   `VPS-DB-02` (`10.10.10.12`) terdaftar dan aktif.
3. Tekan **Ping** pada masing-masing baris. Kolom kondisi harus `up`
   beserta angka RTT.

### Langkah 2 — Pemohon mengajukan akses

Dari PC lain di jaringan yang sama:

4. Buka `http://<IP_KALI>:5173/pengajuan-vpn`.
5. Isi form, pilih **VPS-APP-01** saja, lalu kirim.
6. Catat nomor pengajuan yang muncul, mis. `VPN-2026-0007`.
7. Buka http://localhost:8025 — email **"Pengajuan diterima"** sudah masuk.

### Langkah 3 — Admin meninjau dan menyetujui

8. Menu **Data Pengajuan** → tombol **Detail** pada baris tersebut.
   Tombol keputusan sengaja hanya ada di halaman detail.
9. Pilih paket bandwidth, tekan **Setujui & Provision**.
10. Amati terminal queue worker:

    ```
    App\Jobs\ProvisionAkunJob ....... DONE
    App\Mail\KredensialVpn .......... DONE
    ```

11. Mailpit menerima email **"Akun VPN Anda sudah aktif"** berisi alamat
    server, pre-shared key, username, dan password.

### Langkah 4 — Memeriksa hasil di router

12. Menu **Akun VPN** → **Detail**. Periksa bagian **Objek di router**:
    PPP secret, address list, dan aturan firewall semuanya terisi.
13. Bagian **Riwayat operasi router** menampilkan `provision sukses` beserta
    durasinya dalam milidetik. Angka inilah data Bab 4.

### Langkah 5 — Klien menghubungkan VPN

Dari PC lain, memakai kredensial di email:

14. Tambahkan VPN bertipe **L2TP/IPsec with pre-shared key**, isi alamat
    server, PSK, username, dan password.
15. Sambungkan.

### Langkah 6 — Pembuktian (inti pengujian)

Dari PC yang sudah tersambung VPN:

| # | Uji | Hasil yang diharapkan | Membuktikan |
|---|---|---|---|
| 16 | Buka `http://10.10.10.11` | halaman **VPS-APP-01** tampil | akses yang disetujui berfungsi |
| 17 | Buka `http://10.10.10.12` | timeout | isolasi antar VPS ditegakkan router |
| 18 | `ping 10.10.10.12` | 100% loss | blokir di lapisan jaringan |
| 19 | Buka situs mana pun di internet | tetap terbuka | VPN tidak memutus internet |

Langkah 17 dan 18 adalah klaim keamanan utama penelitian ini. Langkah 19
membuktikan NAT dan DNS untuk klien bekerja.

### Langkah 7 — Log sesi

20. Selagi masih tersambung, buka detail akun di dashboard. Bagian
    **Riwayat sesi koneksi** menampilkan satu baris berlabel
    *sedang terhubung*, lengkap dengan alamat asal.
21. Putuskan VPN, tunggu sekitar 30 detik (atau jalankan
    `php artisan vpn:polling-sesi`), muat ulang halaman. Baris berubah
    menjadi selesai dengan durasi dan volume data.

### Langkah 8 — Operasi siklus hidup

22. **Nonaktifkan** akun dari halaman detail. Coba sambungkan VPN lagi dari
    klien: ditolak. Sesi yang sedang berjalan juga langsung diputus.
23. **Aktifkan** kembali. Klien dapat tersambung lagi.
24. Setiap tindakan menambah baris pada riwayat operasi beserta durasinya.

### Langkah 9 — Deteksi drift

25. Buka Winbox atau SSH ke CHR, ubah profil akun secara manual:

    ```routeros
    /ppp secret set [find name="<username>"] profile=vpn-prioritas
    ```

26. Di dashboard, menu **Sinkronisasi** → **Periksa sekarang**.
    Temuan `ppp_secret.profile` muncul dengan nilai basis data dan nilai router
    bersebelahan.
27. Tekan **Push**. Router dikembalikan mengikuti basis data. Periksa lagi:
    tidak ada temuan.

### Langkah 10 — Kedaluwarsa dan perpanjangan

28. Buat pengajuan baru berdurasi 2 hari, setujui.
29. Jalankan `php artisan vpn:kedaluwarsa`. Akun berubah menjadi
    `akan_kedaluwarsa` dan email peringatan H-3 masuk ke Mailpit.
30. Dari halaman **Cek Status** publik, masukkan nomor pengajuan, tekan
    **Ajukan perpanjangan**, isi tanggal baru.
31. Admin menyetujui perpanjangan. Akun kembali `aktif` dengan tanggal baru,
    dan bila sudah sempat kedaluwarsa, dihidupkan ulang di router.

### Langkah 11 — Penghapusan VPS berantai

32. Menu **Daftar VPS** → **Hapus** pada VPS yang masih punya akun.
33. Dialog konfirmasi menampilkan jumlah akun yang ikut terhapus beserta
    daftar username dan pemohonnya. Tombol berbunyi
    *"Hapus VPS & N akun"*.
34. Konfirmasi, lalu amati queue worker. Setiap akun di-deprovision penuh
    dari router lebih dulu, baru VPS dihapus.
35. Verifikasi tidak ada objek tertinggal:

    ```bash
    php artisan vpn:sinkron --daftar
    ```

    Tidak boleh ada temuan `yatim_di_router`.

### Ringkasan bukti yang dihasilkan

| Bukti | Diambil dari |
|---|---|
| Waktu tiap operasi (ms) | tabel `operasi_router`, tampil di detail akun |
| Kelengkapan deprovisioning | `vpn:sinkron` setelah penghapusan |
| Akurasi deteksi drift | langkah 25–27 |
| Penegakan isolasi | langkah 17–18 |
| Konsistensi basis data dan router | `vpn:sinkron` setelah rangkaian operasi |

---

## 15. Pemecahan masalah yang pernah terjadi

### Edit akun dan keseragaman tampilan (2026-09-06)

Halaman Admin > Akun VPN > Detail memiliki tombol Edit akun untuk username,
password, paket bandwidth, dan satu VPS tujuan. Password kosong mempertahankan
nilai lama. Opsi putus sesi aktif tersedia; tanpa opsi itu, kredensial dan
bandwidth baru berlaku pada koneksi berikutnya. Status dan tanggal akun tidak
diubah oleh edit ini.

Edit memakai job terenkripsi, dengan progres antre/berjalan/sukses/gagal di
riwayat operasi. Database diperbarui setelah penulisan router berhasil;
kegagalan penulisan memicu pemulihan snapshot. Jika pemulihan gagal, admin
diarahkan memeriksa sinkronisasi. Pemutusan sesi yang sudah terjadi tidak
dapat dibatalkan. Akun yang sedang diedit menolak pengajuan edit berikutnya.
Password tidak ditulis dalam audit atau payload operasi router.

`DB_QUEUE_RETRY_AFTER=300` lebih panjang daripada timeout job edit 180 detik
agar job yang masih berjalan tidak diambil ulang terlalu cepat. Setelah
mengubah konfigurasi ini, jalankan `php artisan config:clear`, restart worker,
dan pastikan satu worker kembali aktif.

Verifikasi: 28 tes backend lulus (96 assertions), build frontend berhasil,
rename dan pergantian profile terverifikasi pada akun CHR sementara yang
disabled. Penulisan password diterima API; pembacaan password disamarkan oleh
CHR sehingga nilai baru tidak dapat dibandingkan melalui respons API.
Seluruh akun uji router telah dihapus.

Font antarmuka diseragamkan ke font utama tema, termasuk IP dan kredensial.
Teks frontend tidak menggunakan em dash.

Perbaikan tampilan ping admin (2026-09-06): kolom status kini menampilkan
hasil ping router terakhir dari riwayat, loss, RTT, jumlah kegagalan
berturut-turut, dan waktu pemeriksaan. Hasil gagal tetap terlihat meskipun
status utama masih UP karena belum mencapai ambang tiga pemeriksaan gagal.
Tombol Muat ulang kemudian diganti dengan Periksa semua VPS: mengambil daftar
terbaru lalu memanggil endpoint ping tiap VPS secara berurutan, menampilkan
progres dan memperbarui setiap baris. Kegagalan satu pemeriksaan tidak
menghentikan VPS berikutnya; hasil lama diberi keterangan bila pemeriksaan
gagal. Halaman harus tetap terbuka sampai selesai. Tombol Ping per VPS tetap
tersedia. Jadwal lima menit dan aturan tiga kegagalan tidak berubah.

| Gejala | Penyebab | Perbaikan |
|---|---|---|
| Ping ke VPS 100% loss, ARP kosong | Adapter `VPS Network` gateway tidak aktif; `ether3 running=false` | VirtualBox → Adapter 3 → centang **Enable** dan **Cable Connected** |
| Ping ke `10.10.10.1` berhasil padahal jaringan mati | Router mem-ping alamatnya sendiri secara lokal | Uji dengan alamat VPS, bukan alamat router |
| VPN tersambung, halaman VPS tidak terbuka | VPS tidak punya rute balik ke `10.10.20.0/24` | Tambahkan rute default ke `10.10.10.1` di VPS |
| Dua VPS, salah satu tidak terjangkau | MAC kembar karena OVA yang sama diimpor ulang | Impor ulang dengan **Generate new MAC addresses** |
| PC lain tidak bisa membuka aplikasi | Server terikat `127.0.0.1` | Jalankan dengan `--host=0.0.0.0` |
| Galat CORS di console browser | Origin PC lain belum diizinkan | Sesuaikan `allowed_origins_patterns` |
| Pengajuan disetujui tapi akun tidak muncul | Queue worker tidak berjalan | Jalankan `php artisan queue:work` |
| `Failed to parse dotenv file` | Nilai `.env` mengandung spasi tanpa tanda kutip | Beri tanda kutip, mis. `MAIL_FROM_NAME="Layanan VPN"` |
| Email gagal, `Connection refused :2525` | Mailpit belum jalan atau `MAIL_PORT` salah | `docker start mailpit`, pastikan `MAIL_PORT=1025`, lalu `php artisan config:clear` |
| Klien VPN tersambung tapi internet mati | Aturan NAT masquerade belum ada | Lihat bagian 14c |
| Klien dapat ping IP tapi tidak bisa buka domain | DNS belum dibagikan ke klien | Lihat bagian 14c |
| Email kredensial memuat alamat server yang tidak bisa dihubungi | `ROUTEROS_VPN_SERVER` belum diisi | Isi dengan alamat LAN CHR, bukan alamat host-only |

---

## 10. Riwayat perubahan

| Tanggal | Perubahan |
|---|---|
| 2026-09-05 | Laravel 13.30.1 dipasang; 11 migration skema dijalankan; MariaDB dipasang; pengguna `vpn_app` dibuat; verifikasi foreign key lolos; model Eloquent, enum status, dan seeder dibuat |
| 2026-09-05 | CHR RouterOS 7.23.5 dikonfigurasi lewat `chr-setup.rsc`; `router:cek` lolos 9 prasyarat; uji CRUD `ppp/secret` ke router sungguhan berhasil |
| 2026-09-05 | Layanan provisioning + rollback; `vpn:uji-siklus` lolos penuh tanpa objek yatim; 31 endpoint API; dashboard admin Vue (login, pengajuan, akun, VPS, sinkronisasi) |
| 2026-09-05 | Log sesi, kedaluwarsa otomatis, perpanjangan, dan deteksi drift; siklus hidup terbukti tertutup (provision → disable → enable → expire → extend → aktif) |
| 2026-09-05 | Dua VPS tiruan berbasis CHR di `VPS Network`; koneksi L2TP dari klien LAN berhasil; aplikasi dibuka untuk akses LAN |
| 2026-09-06 | Tampilan ping VPS diperjelas dan tombol Periksa semua VPS ditambahkan; status DOWN tetap memakai ambang tiga kegagalan |
| 2026-09-06 | Font UI diseragamkan dan em dash dihapus dari teks frontend sesuai preferensi pengguna |
| 2026-09-06 | Edit akun username, password, paket, dan VPS tujuan ditambahkan melalui job terenkripsi dengan rollback router dan audit tanpa password; 28 tes backend (96 assertions) serta build frontend lulus |

---

## 11. Yang belum dikerjakan

Diperiksa ulang 2026-09-06 terhadap kondisi kode, bukan ingatan.

### Sudah selesai

- [x] CHR dinyalakan dan dikonfigurasi (bagian 12)
- [x] `php artisan router:cek` lolos seluruh prasyarat
- [x] Dua VM VPS disiapkan di jaringan `VPS Network` (bagian 12.6)
- [x] Koneksi L2TP dari klien pada jaringan LAN berhasil
- [x] Edit username, password, paket bandwidth, dan VPS tujuan dari detail akun
- [x] Periksa seluruh VPS dari satu tombol dengan progres dan hasil per VPS
- [x] Email queued: pengajuan diterima, ACC + kredensial, penolakan, dan H-3
- [x] Hapus VPS berantai yang membersihkan seluruh akun dari router
- [x] Log sesi, kedaluwarsa otomatis, perpanjangan, deteksi drift push/pull
- [x] Klien VPN tetap dapat internet (NAT masquerade + DNS)
- [x] Halaman Pengaturan: sembilan parameter operasional + CRUD paket bandwidth
      yang merambat ke PPP profile di router

### Belum — berkaitan dengan kode

- [x] Seluruh operasi router dipindahkan ke antrean (keputusan 6.2 terpenuhi).
      Tujuh operasi yang tersisa kini lewat job: disable, enable, hapus akun,
      perpanjangan, ping VPS, pemeriksaan drift, dan resolusi drift. Status
      koneksi router untuk dashboard disegarkan penjadwal ke cache, sehingga
      halaman tidak lagi menunggu timeout ketika router mati.

- [ ] Grafik penggunaan bandwidth (butir NILAI+ terakhir). Datanya sudah
      lengkap di `sesi_vpn`, tinggal divisualisasikan.
- [ ] Pengaturan Tier B: rotasi IPsec pre-shared key dan tombol nyala-mati
      layanan L2TP, keduanya dengan konfirmasi jumlah akun terdampak.

### Belum — berkaitan dengan pengujian dan penulisan

- [ ] Uji isolasi antar-VPS didokumentasikan sebagai bukti (prosedur di bagian
      14.2, dapat dijalankan dari CHR klien maupun PC)
- [ ] Pengukuran Bab 4: bandingkan durasi operasi manual lewat Winbox dengan
      angka `durasi_ms` yang sudah terkumpul otomatis di `operasi_router`
      (lihat CLAUDE.md bagian 8)
- [ ] Trial lisensi CHR p-unlimited 60 hari, diperlukan bila ingin menguji
      perbedaan paket bandwidth di atas 1 Mbps
- [ ] Tuliskan di Batasan Masalah: **Android 12 ke atas menghapus L2TP dari
      klien bawaan**, sehingga pengujian klien dilakukan dari PC dan MikroTik
      CHR. Keputusan #1 menyebut L2TP dipilih karena tersedia native di semua
      platform; asumsi itu perlu dikoreksi.
- [ ] Tuliskan di Batasan Masalah: VPS disimulasikan dengan mesin virtual
      RouterOS, bukan sistem operasi server.

### Belum — berkaitan dengan penerapan

- [ ] Supervisor untuk queue worker dan penjadwal, agar keduanya hidup kembali
      setelah mesin dinyalakan ulang
- [ ] Ganti kredensial bawaan bila sistem dipasang di lingkungan yang dapat
      diakses jaringan luar

### Sengaja tidak dikerjakan

- Rantai diagnosa penuh (CLAUDE.md 4.8 bagian B), dicoret atas keputusan
  pengguna 2026-09-05
- Tombol "Putuskan Sesi" terpisah, sudah tercakup dalam operasi Disable
- Pengaturan Tier C: alamat interface, rute, sertifikat, pengguna RouterOS,
  dan aturan firewall fondasi. Sistem tidak boleh mengatur hal yang menjadi
  prasyarat hidupnya sendiri.
