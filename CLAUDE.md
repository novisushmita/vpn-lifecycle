# Acuan Proyek — Sistem Manajemen Siklus Hidup Akun VPN

> Dokumen tunggal acuan untuk **semua agent** yang mengerjakan proyek ini.
> Baca penuh sebelum menulis kode. Jangan mengambil keputusan yang sudah
> dikunci di bagian "Keputusan Terkunci" tanpa persetujuan eksplisit user.

Terakhir diperbarui: 2026-09-05

---

## 0. Konteks

Ini **Tugas Akhir (skripsi)**, bukan proyek produksi. Konsekuensinya:

- Kode harus bisa dijelaskan dan dipertahankan di sidang. Boring > clever.
- Setiap fitur harus punya kaitan jelas dengan salah satu fase siklus hidup (bagian 2).
- Yang tidak bisa diukur atau dijelaskan, jangan dibangun.
- Bahasa UI, komentar berorientasi domain, dan istilah: **Bahasa Indonesia**.
  Nama variabel/tabel/kode: campuran wajar (istilah domain Indonesia seperti
  `pengajuan`, istilah teknis Inggris seperti `provisioning`). Ikuti pola
  yang sudah ada di `vpn-frontend/src/data/`.

### Gap penelitian yang diangkat

Penelitian terdahulu berfokus pada **otomasi provisioning** akun VPN — yaitu
transisi **CREATE** saja (cara membuat akun otomatis dan seberapa cepat).
Mereka berhenti begitu akun jadi.

Padahal siklus hidup sebuah akun punya tujuh transisi, dan enam di antaranya
tidak pernah dibahas:

```
CREATE   -> provisioning otomatis         <- ini yang dibahas jurnal terdahulu
UPDATE   -> ganti password / paket / VPS  <- tidak dibahas
DISABLE  -> nonaktifkan sementara         <- tidak dibahas
ENABLE   -> aktifkan kembali              <- tidak dibahas
DELETE   -> hapus + bersihkan sisa        <- tidak dibahas
EXPIRE   -> otomatis berdasarkan tanggal  <- tidak dibahas
EXTEND   -> perpanjang masa akses         <- tidak dibahas
```

**Fokus proyek ini: mengotomasi SELURUH transisi di atas**, di mana setiap
transisi wajib (1) mengubah state di database, (2) merambat ke MikroTik,
(3) dijamin konsisten antara keduanya.

> INTI TEMA: lifecycle = **mengubah** state akun secara terkontrol dan
> tersinkron. Fitur apa pun yang tidak melayani ini adalah kandidat scope
> creep dan harus dipertanyakan dulu ke user.

Tiga fitur pembeda utama (JANGAN dipotong tanpa izin user):
1. **Kontrol siklus hidup penuh oleh admin** — edit kredensial/paket, disable,
   enable, hapus; semuanya merambat ke router, bukan sekadar ubah database
2. **Drift detection & rekonsiliasi** — mekanisme PENJAMIN bahwa ketujuh
   transisi di atas benar-benar konsisten. Bukan fitur berdiri sendiri;
   ia melayani lifecycle
3. **Expiry & perpanjangan otomatis** — menutup lubang keamanan akun
   kedaluwarsa yang masih hidup di router

---

## 1. Tech Stack

| Lapisan | Teknologi |
|---|---|
| Frontend | Vue 3 + Vite + Vue Router (**sudah ada**, lanjutkan, jangan rewrite) |
| Backend | Laravel versi terbaru |
| Database | MySQL / MariaDB |
| Queue & Scheduler | Laravel Queue + Scheduler, dijalankan permanen via Supervisor |
| VPN Server | MikroTik **CHR** (Cloud Hosted Router), **RouterOS v7** |
| Integrasi router | **REST API RouterOS v7** (`/rest/...`) |
| Protokol VPN | **PPP-based**, utama: **L2TP/IPsec** |

**Queue worker + scheduler WAJIB jalan.** Bukan opsional. Semua operasi ke
router (ping, provisioning, polling sesi, drift check, expiry) berjalan lewat
queue, tidak pernah di dalam request HTTP.

---

## 2. Delapan Fase Siklus Hidup (kerangka wajib)

Setiap fitur harus bisa dipetakan ke salah satu fase ini.

```
 [1] REQUEST      pengajuan dari user
      v
 [2] REVIEW       admin lihat detail -> ACC / REJECT
      v
 [3] PROVISION    buat akun + firewall di MikroTik otomatis
      v
 [4] OPERATE      uptime, log sesi, monitoring
      v
 [5] DIAGNOSE     fitur diagnosa "akun tidak bisa diakses"
      v
 [6] ENFORCE      limit bandwidth, drift detection & rekonsiliasi
      v
 [7] EXPIRE       durasi habis -> nonaktif otomatis (+ perpanjangan)
      v
 [8] DEPROVISION  hapus akun + audit trail
```

### State machine akun VPN

```
draft -> diajukan -> ditinjau -> ditolak (selesai)
                            \-> disetujui -> provisioning -> AKTIF
                                                  |
                                   (gagal) -> gagal_provision -> (retry admin)

Dari status AKTIF, transisi yang dikendalikan admin:

  AKTIF --[admin: disable]--> DINONAKTIFKAN
  DINONAKTIFKAN --[admin: enable]--> AKTIF
  AKTIF --[admin: edit]--> AKTIF        (kredensial/paket/VPS berubah)
  AKTIF --[admin: hapus]--> DIHAPUS     (deprovision penuh)

Transisi yang dikendalikan waktu (scheduler):

  AKTIF --[H-3]--> AKAN_KEDALUWARSA --[admin ACC extend]--> AKTIF
  AKAN_KEDALUWARSA --[lewat tanggal]--> KEDALUWARSA
  KEDALUWARSA --[H+30]--> DIHAPUS (sisakan audit record)
```

Status apa pun bisa masuk kondisi **drift** (state DB != state router).
Drift bukan status akun, melainkan **temuan** yang dicatat terpisah dan
ditampilkan di dashboard.

---

## 3. Keputusan Terkunci

Sudah diputuskan user. **Jangan diubah, jangan ditawar ulang.**

| # | Keputusan | Konsekuensi |
|---|---|---|
| 1 | **Protokol PPP-based**, utama L2TP/IPsec | Semua fitur (username/password, uptime, log sesi, rate-limit, putus paksa) bisa dibangun. **WireGuard DITOLAK** karena tanpa konsep sesi. L2TP native di Windows/Android/iOS/macOS -> tidak perlu instal aplikasi klien |
| 2 | **Log aktivitas = sesi saja** (Tingkat 1) | Catat: siapa konek, jam berapa, IP yang didapat, jam putus, total upload/download. TIDAK ada NetFlow, TIDAK ada DPI |
| 3 | **Isolasi per-VPS lewat firewall** | Provisioning adalah transaksi 3 langkah, harus idempotent + rollback (lihat 4.3) |
| 4 | **Lingkungan uji: MikroTik CHR, RouterOS v7** | Pakai REST API. Perhatikan jebakan lisensi (lihat 6.1) |
| 5 | **RouterOS v7** | Pakai REST API `/rest/`, bukan API socket 8728/8729 |
| 6 | **Pemohon TIDAK punya login** | Identifikasi via nomor pengajuan. Kredensial dikirim lewat **email**. Halaman cek status **TIDAK PERNAH** menampilkan password |
| 7 | **Bandwidth = paket bertingkat** | Dasar 2M/2M, Standar 5M/5M, Prioritas 10M/10M. **Tanpa kuota volume** |
| 8 | **Satu peran admin** | Tidak ada persetujuan bertingkat, tidak ada pemisahan verifikator/teknisi |
| 9 | **Ada perpanjangan akses** | User ajukan extend -> admin ACC -> update tanggal + re-enable. Tanpa ini fase 7 jadi jalan buntu |
| 10 | **Email masuk scope**, queued (tidak blocking) | Notifikasi: pengajuan diterima, ACC + kredensial, ditolak, H-3 kedaluwarsa. Lab memakai **Mailpit** (SMTP 1025, UI 8025) |
| 11 | **Skala uji: ~10 VPS, ~20 akun, ~5 sesi bersamaan** | Polling `/ppp active` tiap 30 detik, ping tiap 5 menit -> aman di skala ini |
| 12 | **Audit log tindakan admin = scope inti** | Wajib, bukan opsional. Karena admin bisa melihat password akun, harus tercatat siapa melihat apa dan kapan |

---

## 4. Spesifikasi Fitur & Mekanisme

### 4.1 Dashboard admin — login

Auth admin standar Laravel. Satu peran. Tidak ada registrasi publik; akun
admin dibuat lewat seeder/command.

### 4.2 Data pengajuan -> lihat DETAIL dulu -> ACC / REJECT

Alur wajib: admin **tidak boleh** ACC/REJECT langsung dari list. Harus buka
halaman detail dulu, baru tombol ACC/REJECT muncul. Ini permintaan eksplisit
user.

- REJECT wajib mengisi alasan penolakan (masuk ke email notifikasi).
- ACC memicu job provisioning (4.3).

### 4.3 Provisioning (fase 3) — transaksi 3 langkah

```
1. Buat /ppp secret (username, password, profile sesuai paket bandwidth)
2. Daftarkan IP akun ke address-list khusus akun tsb
3. Pasang aturan firewall filter: address-list ini -> hanya boleh ke IP VPS yang disetujui
```

**Wajib idempotent + rollback.** Kalau langkah 3 gagal, langkah 1-2 harus
dibatalkan. Jangan pernah meninggalkan akun setengah jadi. Status
`gagal_provision` dengan pesan error tersimpan, dan admin bisa retry.

Job harus aman dijalankan ulang (cek dulu apakah resource sudah ada sebelum
membuat).

### 4.4 CRUD VPS + ping (fase 4)

**Ping dijalankan DI MikroTik lewat API** (`/ping address=... count=3`),
BUKAN dari server Laravel. Alasan: paket client VPN keluar lewat router itu,
jadi ping dari router = sudut pandang yang sama dengan client. Ping dari
server Laravel bisa menyesatkan (Laravel bilang "hidup", client tetap tidak
bisa akses).

Opsional sebagai pembanding: ping dari server Laravel juga disimpan. Kalau
Laravel gagal + router sukses = masalah di jalur luar, bukan di VPS.

**Aturan eksekusi (KRITIS):**

> JANGAN ping di dalam request HTTP. Ping 3 paket + timeout = ~3 detik.
> 20 VPS -> request menggantung 60 detik -> PHP-FPM worker habis.

Yang benar:
- Scheduler tiap **5 menit** -> dispatch job per VPS ke queue -> worker kirim
  perintah ping ke RouterOS -> simpan ke tabel `vps_health_checks`
  (`rtt_avg`, `packet_loss`, `status`, `checked_at`).
- Halaman list VPS hanya membaca baris terakhir dari tabel itu. Instan.
- Tombol "Ping sekarang" = dispatch job on-demand, frontend polling sampai
  hasil masuk.
- **Flap protection**: jangan tandai DOWN dari 1 kegagalan. Butuh **3 kali
  gagal berturut-turut** baru status berubah. Mencegah alarm palsu.

#### Dua bentuk data VPS — publik vs admin (WAJIB DIPISAH)

`alamat_ip` adalah **data internal**. Pemohon tidak punya login (keputusan #6),
jadi endpoint publik dapat diakses siapa saja tanpa autentikasi.

| Konsumen | Endpoint | Field yang dikembalikan |
|---|---|---|
| Publik (form pengajuan) | `GET /api/vps` | `id`, `nama`, `keterangan` — **HANYA INI** |
| Admin (dashboard) | `GET /api/admin/vps` | seluruh field termasuk `alamat_ip`, status ping, rtt |

> **JANGAN pakai satu API Resource untuk dua konsumen.** Buat dua kelas
> terpisah, mis. `VpsPublikResource` dan `VpsAdminResource`. Kalau dipakai
> satu lalu di-filter di frontend, `alamat_ip` tetap terkirim di response JSON
> dan terlihat di DevTools. Menyembunyikan di UI bukan menyembunyikan data.
>
> Endpoint publik juga hanya menampilkan VPS dengan `aktif = true` dan
> `sedang_dihapus = false`.

### 4.4b Hapus VPS — operasi lifecycle berantai

Menghapus VPS berarti **setiap akun VPN yang terikat padanya harus
di-deprovision penuh dari router lebih dulu**.

> **JANGAN pakai `onDelete('cascade')` pada foreign key.** Cascade database
> hanya menghapus baris `akun_vpn`; `/ppp secret`, address-list, dan aturan
> firewall-nya **tetap hidup di MikroTik**. Hasilnya: sistem lupa akun itu
> pernah ada, tapi akunnya masih bisa dipakai konek — dan sekarang tidak
> terlacak sama sekali. Ini kebalikan dari tujuan penelitian ini.

Urutan yang benar:

```
1. Tolak bila masih ada operasi berjalan untuk VPS ini
2. Tandai vps.sedang_dihapus = true
   -> VPS langsung hilang dari endpoint publik, tidak ada pengajuan baru masuk
3. Batalkan semua pengajuan berstatus diajukan/ditinjau yang menunjuk VPS ini
   (status -> ditolak, alasan otomatis, kirim email)
4. Untuk SETIAP akun_vpn milik VPS ini, jalankan urutan hapus akun (4.5d):
   putus sesi -> hapus firewall rule -> hapus address-list -> hapus ppp secret
   -> soft delete, alasan_penghapusan = vps_dihapus
5. Hanya bila SEMUA akun sukses dibersihkan -> soft delete baris vps
6. Bila ada yang gagal -> vps TETAP di status sedang_dihapus,
   sisanya tercatat sebagai drift 'yatim_di_router', admin bisa retry
```

**Kenapa langkah 5 tidak boleh dilewat:** kalau VPS dihapus lebih dulu, sistem
kehilangan referensi ke akun yang belum sempat dibersihkan, dan objek yatim di
router jadi tidak punya pemilik untuk dilacak.

**Alasan keamanan di balik semua ini** (tulis di skripsi): aturan firewall
mengizinkan akses berdasarkan **alamat IP**. Kalau aturan lama tertinggal lalu
kemudian ada VPS baru didaftarkan dengan IP yang sama, akun-akun lama otomatis
punya akses ke VPS baru itu tanpa pernah disetujui siapa pun. Penghapusan
berantai inilah yang menutupnya.

Konfirmasi di UI wajib menampilkan **jumlah akun yang akan ikut terhapus**
sebelum admin menekan tombol.

### 4.5 Operasi lifecycle akun oleh admin (fase 4/6/8) — TEMA INTI

**Ini jantung TA, bukan CRUD biasa.** Setiap operasi di bawah adalah transisi
state machine yang WAJIB merambat ke MikroTik. Mengubah database tanpa
merambat ke router = menciptakan drift = kegagalan sistem.

Aturan yang berlaku untuk KEEMPAT operasi:
- Dijalankan lewat queue job, idempotent, punya rollback (sama seperti 4.3).
- Kalau perambatan ke router gagal -> status DB TIDAK ikut berubah, tampilkan
  error, sediakan retry. Jangan pernah DB bilang X padahal router bilang Y.
- Semua tercatat di audit log (4.11).

#### a. EDIT akun

Yang bisa diubah: username, password, paket bandwidth, VPS tujuan, tanggal
selesai.

| Field diubah | Yang harus dilakukan di router |
|---|---|
| Password | `set` password pada `/ppp secret` |
| Paket bandwidth | `set` `rate-limit` / ganti PPP profile |
| VPS tujuan | Perbarui address-list + aturan firewall filter |
| Username | Nama secret adalah identitasnya. Aman: buat baru + hapus lama, atau `set name`. **Perlu diverifikasi di CHR asli** |
| Tanggal selesai | Cukup di DB; memengaruhi jadwal scheduler fase 7 |

> **GOTCHA PENTING:** perubahan password dan rate-limit **TIDAK berlaku pada
> sesi yang sedang aktif**. Sesi berjalan memakai nilai saat ia terhubung.
> Perubahan baru berlaku pada koneksi berikutnya.
>
> Maka UI edit WAJIB menawarkan opsi: **"Putuskan sesi aktif sekarang agar
> perubahan langsung berlaku"**. Tanpa ini admin akan mengira sistemnya rusak.

#### b. DISABLE akun (nonaktifkan sementara)

```
1. /ppp secret set disabled=yes
2. WAJIB: putuskan sesi aktif di /ppp active
3. Status DB -> dinonaktifkan
```

> **GOTCHA PENTING:** `disabled=yes` pada secret **hanya mencegah login
> berikutnya**. Sesi yang sedang berjalan TIDAK ikut terputus dan user tetap
> terhubung sampai ia putus sendiri.
>
> Langkah 2 tidak boleh dilewat. Ini jebakan klasik yang bikin fitur disable
> terlihat "berhasil" padahal user masih online.

#### c. ENABLE akun (aktifkan kembali)

```
1. Cek dulu: akun belum melewati tanggal selesai (kalau lewat, tolak dan
   arahkan ke alur perpanjangan)
2. Pastikan address-list + firewall rule masih ada; kalau hilang, pasang ulang
3. /ppp secret set disabled=no
4. Status DB -> aktif
```

Langkah 2 penting: aturan firewall bisa hilang kalau router pernah di-reboot
tanpa konfigurasi tersimpan, atau dihapus manual.

#### d. HAPUS akun (deprovision penuh)

Kebalikan urutan provisioning (4.3), dari luar ke dalam:

```
1. Putuskan sesi aktif di /ppp active
2. Hapus aturan firewall filter milik akun
3. Hapus entri address-list
4. Hapus /ppp secret
5. Soft delete di DB -> status dihapus, SISAKAN audit record & riwayat sesi
```

**Jangan hard delete baris database.** Riwayat sesi dan jejak audit harus
tetap ada untuk kebutuhan pelaporan skripsi dan pertanggungjawaban.

Kalau salah satu langkah gagal, catat sebagai **sisa yatim (orphan)** yang
akan tertangkap drift detection (4.9) supaya bisa dibersihkan kemudian.

#### e. Halaman detail akun

Menampilkan: VPS yang terhubung, username, password, paket bandwidth,
tanggal mulai/selesai, status, sesi aktif saat ini, total durasi pemakaian,
riwayat sesi, dan panel **Status Sinkronisasi** (lihat 4.8).

Tombol aksi: Edit, Disable/Enable, Hapus.

> **KEPUTUSAN USER 2026-09-05: tidak ada tombol "Putuskan Sesi" terpisah.**
> Pemutusan sesi sudah menjadi bagian wajib dari operasi Disable dan Hapus,
> dan itu dianggap cukup. `ProvisioningService::putusSesi()` tetap ada sebagai
> method internal.

**Penyimpanan password:** disimpan **terenkripsi** (Laravel `encrypted` cast),
**bukan hash**, karena harus bisa ditampilkan ulang ke admin dan RouterOS
sendiri menyimpannya secara reversible. Ini penyimpangan sadar dari prinsip
umum. Mitigasi: enkripsi at-rest + **audit log setiap kali kredensial
dilihat**. Wajib ditulis di Batasan Masalah skripsi (butir 13).

**Uptime — dua makna, jangan tertukar:**
- *Uptime sesi berjalan* -> langsung dari `/ppp active`, real-time.
- *Total durasi pemakaian akun* -> dijumlahkan dari riwayat tabel sesi.

Dua-duanya harus ada.

### 4.6 Log sesi (fase 4)

Polling `/ppp active` tiap **30 detik**, bandingkan dengan state sebelumnya
untuk mendeteksi sesi baru / sesi hilang. Simpan ke tabel sesi:
waktu konek, waktu putus, IP yang didapat, bytes in/out, durasi.

(Alternatif yang lebih rapi: RADIUS accounting. Hanya kalau user setuju
menambah komponen; default = polling.)

### 4.7 Pengaturan bandwidth (fase 6)

Paket bertingkat, di-set lewat `rate-limit` pada PPP secret atau PPP profile.

| Paket | rx/tx |
|---|---|
| Dasar | 2M / 2M |
| Standar | 5M / 5M |
| Prioritas | 10M / 10M |

Opsional untuk kestabilan lebih baik: PCQ, supaya pembagian antar user adil
secara dinamis. Tidak wajib.

> PERINGATAN: nama field dan perilaku `rate-limit` / `limit-bytes-*` sedikit
> berbeda antar versi RouterOS. **Verifikasi langsung di CHR yang dipakai**,
> jangan percaya bulat-bulat dokumentasi versi lain.

### 4.8 Diagnosa — !! DIPUTUSKAN: RANTAI PENUH DICORET !!

> **KEPUTUSAN USER 2026-09-05: bagian B TIDAK DIBANGUN.** Hanya bagian A
> (panel Status Sinkronisasi) yang dikerjakan, dan itu sudah selesai.
> Jangan menambahkan rantai diagnosa penuh; bila muncul lagi sebagai usulan,
> tolak dengan merujuk butir ini.

**Kenapa dipertanyakan:** diagnosa adalah sumbu yang BERBEDA dari lifecycle.
Lifecycle = *mengubah* state akun. Diagnosa = *memeriksa* kenapa sesuatu tidak
jalan, tanpa mengubah apa pun. Mempertahankan dua tema sekaligus membuat bab
pembahasan skripsi terbelah.

---

#### A. Yang DISETUJUI dibangun — panel "Status Sinkronisasi"

Bukan menu terpisah. Cukup panel kecil 3 baris di halaman detail akun (4.5e).
Biayanya **nol kerja tambahan** karena datanya sudah dihasilkan pekerjaan
lifecycle yang memang harus dibangun:

| Baris | Sumber data | Biaya |
|---|---|---|
| Status akun menurut database | tabel akun | gratis |
| Secret ada & enabled di router? | hasil drift check (4.9) | gratis |
| Ada sesi aktif sekarang? | polling `/ppp active` (4.6) | gratis |

Secara tematik ini **masih lifecycle**: menjawab "apakah state akun ini
benar-benar seperti yang sistem klaim?". Bukan troubleshooting.

---

#### B. Yang DITAHAN — rantai diagnosa penuh

Hanya dikerjakan kalau user menyetujui DAN waktu tersisa. Fondasinya sama
dengan bagian A, jadi bisa ditambahkan belakangan tanpa membongkar apa pun.

Satu tombol "Diagnosa" di halaman detail akun. Menjalankan rantai pemeriksaan
berurutan, hasilnya ditampilkan sebagai **checklist**, bukan sekadar "error".

| # | Yang diperiksa | Kalau gagal, artinya |
|---|---|---|
| 1 | Status akun di DB Laravel | Akun sudah kedaluwarsa / ditangguhkan admin |
| 2 | Secret ada & enabled di MikroTik | **Drift** — DB dan router tidak sinkron |
| 3 | Ada sesi aktif di `/ppp active`? | Client memang belum konek (bukan masalah server) |
| 4 | Log router: ada percobaan login gagal? | Salah password / salah protokol / IPsec secret salah |
| 5 | ~~Kuota / limit-bytes terlampaui?~~ | **DICORET** — keputusan #7: tidak pakai kuota volume |
| 6 | Router bisa ping VPS tujuannya? | Masalah di VPS, bukan di VPN |
| 7 | Aturan firewall untuk akun ini masih ada? | Provisioning gagal separuh jalan |

Poin 1, 2, 3 = **bagian A**, gratis, sudah disetujui.
Poin 4, 6, 7 = **bagian B**, kerja tambahan, DITAHAN sampai user memutuskan.

Kalau bagian B jadi dibangun: poin 3 dan 6 yang paling berharga, karena
membedakan **masalah di sisi client** vs **masalah di sisi server** — itu yang
selama ini bikin admin harus buka Winbox dan menebak-nebak.

### 4.9 Drift detection & rekonsiliasi (fase 6) — nyawa TA

Masalah nyata: DB bilang akun Budi aktif limit 2 Mbps, tapi ada admin yang
mengubahnya manual lewat Winbox. Sekarang **DB berbohong**.

Mekanisme:
- Job periodik membandingkan seluruh state DB dengan state sebenarnya di
  MikroTik.
- Setiap selisih dicatat sebagai **drift** dan tampil di dashboard.
- Admin memilih: **push** (paksa router ikut DB) atau **pull** (DB ikut router).

Ini pola **desired state reconciliation** — prinsip yang sama dengan
Kubernetes dan Terraform. Sebutkan istilah ini di skripsi: menaikkan posisi
dari "bikin aplikasi" jadi "menerapkan pola arsitektur pada domain manajemen
VPN".

### 4.10 Expiry otomatis (fase 7)

Scheduler harian:
- **H-3** sebelum tanggal selesai -> kirim email peringatan.
- **Lewat tanggal selesai** -> disable secret di router, putuskan sesi aktif,
  status jadi `kedaluwarsa`.
- **H+30** -> hapus permanen, sisakan catatan audit.

Perpanjangan: user ajukan extend -> admin ACC -> update tanggal + re-enable.

### 4.11 Audit log tindakan admin (scope inti)

Catat minimal: login admin, ACC/REJECT pengajuan, CRUD VPS, CRUD akun VPN,
**melihat kredensial akun**, aksi rekonsiliasi drift, suspend/resume akun.
Simpan: siapa, apa, kapan, IP admin.

---

## 5. Kondisi Frontend Saat Ini

Lokasi: `vpn-frontend/`. Vue 3 + Vite + Vue Router. Integrasi HTTP memakai
`src/lib/api.js` berbasis `fetch`, token admin disimpan melalui
`src/lib/auth.js`, dan route admin dilindungi navigation guard.

**Halaman publik yang sudah terhubung API:**
- daftar VPS publik melalui `GET /api/vps`;
- pengajuan VPN melalui `POST /api/pengajuan`;
- cek status melalui `GET /api/pengajuan/{nomor}`;
- pengajuan perpanjangan melalui `POST /api/perpanjangan`;
- Panduan dan Bantuan tetap berupa konten statis.

**Halaman admin yang sudah ada:** login, dashboard, daftar/detail pengajuan,
daftar/detail akun, CRUD VPS, pemeriksaan VPS, serta daftar dan rekonsiliasi
drift. Detail akun mendukung edit username, password, paket bandwidth, dan
satu VPS tujuan melalui job antrean terenkripsi.

Pemeriksaan VPS menampilkan hasil ping terakhir, packet loss, RTT, waktu cek,
dan hitungan kegagalan. Tombol `Periksa semua VPS` memeriksa semua VPS secara
berurutan dan memperbarui setiap baris. Status utama baru berubah menjadi DOWN
setelah tiga pemeriksaan gagal berturut-turut.

**Aturan untuk agent:**
- **JANGAN rewrite frontend.** Lanjutkan yang ada.
- Seluruh akses API tetap melalui `src/lib/api.js`; jangan memanggil `fetch`
  langsung dari komponen baru.
- Dashboard admin = area baru dengan layout terpisah + auth guard. Jangan
  campur dengan halaman publik.
- `durasi_mulai` dan `durasi_selesai` adalah pemicu fase kedaluwarsa dan tidak
  boleh dibuang.
- Ikuti `theme.css` yang sudah ada. Jangan tambah UI framework baru.
- Semua tampilan memakai keluarga font utama yang sama dan teks UI tidak
  memakai em dash.

---

## 6. Jebakan yang Sudah Diketahui

### 6.1 Lisensi CHR membatasi 1 Mbps

Lisensi gratis CHR membatasi throughput **1 Mbps per interface**. Kalau
menguji limit bandwidth (beda paket 2M vs 5M vs 10M), hasilnya tidak akan
kelihatan karena semua mentok di 1 Mbps duluan.

**Solusi:** pakai **trial p-unlimited 60 hari** (gratis, cukup daftar akun
MikroTik) dan jadwalkan pengujian bandwidth di dalam masa itu. Kalau tidak,
tulis di batasan bahwa pengujian bandwidth dilakukan pada skala di bawah
1 Mbps.

### 6.2 Jangan blocking di request HTTP

Semua operasi ke router (ping, provisioning, polling, drift check) lewat
queue. Tidak ada pengecualian.

**Pola yang dipakai** (terpasang penuh 2026-09-06):

1. Controller membuat baris `operasi_router` berstatus `antre` sebagai penanda,
   lalu mengantrekan job dan membalas **202** beserta `operasi_id`.
2. Job memperbarui baris yang sama, bukan membuat baris baru. Untuk operasi
   akun, `ProvisioningService::pakaiOperasi()` memastikan itu.
3. Antarmuka memantau `GET /api/admin/operasi/{id}` sampai status berubah
   menjadi `sukses` atau `gagal`.

Controller juga menolak permintaan baru dengan **409** selama masih ada operasi
`antre`/`berjalan` untuk akun yang sama, supaya dua perintah tidak saling
menimpa di router.

**Kegagalan menghubungi router ditangani berlapis:**

1. `ProvisionAkunJob` mencoba ulang **5 kali** dengan jeda 15, 60, 300, dan 900
   detik. Percobaan terakhir jatuh sekitar 21 menit setelah yang pertama,
   cukup menutupi router yang dinyalakan ulang atau pemeliharaan singkat.
2. Bila tetap gagal, akun berhenti di `gagal_provision` dengan pesan
   tersimpan, dan job masuk `failed_jobs`. **Tidak ada percobaan ulang
   otomatis setelah titik ini.**
3. Deteksi drift menangkapnya sebagai `hilang_di_router` pada pemindaian
   berikutnya, sehingga akun yang tersangkut selalu muncul di dashboard,
   bukan menghilang diam-diam.
4. Admin memulihkannya dengan **Ulangi Proses** di detail akun, atau
   **Terapkan ke Router** dari halaman Pemeriksaan Router.

> Percobaan ulang otomatis sengaja tidak dibuat tak terbatas. Router yang mati
> berjam-jam adalah masalah yang harus dilihat manusia, bukan disembunyikan
> oleh sistem yang terus mencoba sendiri.

> **Status koneksi router untuk dashboard disegarkan penjadwal ke cache**
> (`CekKoneksiRouterJob`, tiap menit). Dashboard hanya membaca cache. Tanpa itu,
> membuka dashboard saat router mati berarti menunggu timeout penuh sebelum
> halaman muncul, padahal justru saat itulah dashboard paling dibutuhkan.

### 6.2b Klien VPN wajib tetap bisa internet

Trafik klien menuju internet sudah lolos chain `forward`; yang mudah terlupa
adalah **NAT**. Tanpa aturan masquerade, paket keluar tetapi balasannya tidak
tahu jalan pulang, dan gejalanya tampak seperti "VPN memutus internet".

```routeros
# out-interface = interface bridged CHR yang menuju LAN/internet (ether1 di lab ini)
/ip firewall nat
add chain=srcnat src-address=10.10.20.0/24 out-interface=ether1 action=masquerade
/ip dns
set servers=8.8.8.8,1.1.1.1 allow-remote-requests=yes
/ppp profile
set [find name~"^vpn-"] dns-server=10.10.20.1
```

Tanpa baris DNS, klien terhubung dan dapat menjangkau VPS lewat alamat IP,
tetapi tidak dapat membuka nama domain apa pun.

### 6.3 Topologi: VPS ada di JARINGAN INTERNAL di belakang MikroTik

**Dikonfirmasi user 2026-09-05.** VPS memakai alamat **privat**, berada di
belakang MikroTik. Konsekuensi yang membuat desain ini valid:

- Semua trafik klien VPN menuju VPS **pasti melewati router**, sehingga aturan
  firewall pada chain `forward` selalu berlaku. Isolasi per-VPS (keputusan #3)
  benar-benar mengikat, bukan sekadar administratif.
- Ping dari router (4.4) memang mewakili jalur yang dilalui klien.
- VPS tidak dapat dijangkau tanpa VPN, sehingga sistem ini benar-benar menjadi
  satu-satunya pintu masuk.

> Kalau suatu saat ada VPS yang dipindah ke IP publik di luar router, seluruh
> alasan di atas gugur untuk VPS tersebut: pada VPN split tunnel klien bisa
> menjangkaunya langsung tanpa menyentuh aturan firewall. Skenario itu
> **di luar cakupan** dan tidak boleh dibangun tanpa membahas ulang dengan user.

### 6.4 Konfigurasi dasar router diasumsikan sudah ada

IP pool, interface, IPsec proposal, sertifikat — di luar scope sistem.
Sistem hanya mengelola siklus hidup akun.

---

## 7. Batasan Masalah (untuk Bab 1 skripsi)

**Teknis**
1. VPN server terbatas pada **MikroTik RouterOS** dengan protokol berbasis PPP; vendor lain (Cisco, pfSense, dll.) di luar cakupan.
2. Sistem mengelola **satu router** sebagai VPN gateway; multi-router / HA / failover tidak dicakup.
3. Konfigurasi dasar router (IP pool, interface, IPsec proposal, sertifikat) diasumsikan **sudah ada**; sistem hanya mengelola siklus hidup akun, bukan instalasi router dari nol.
4. Pembatasan bandwidth memakai mekanisme bawaan RouterOS; sistem tidak membangun QoS engine sendiri.
5. VPS diperlakukan sebagai **target akses** yang identitas dan alamatnya dicatat; sistem tidak melakukan manajemen ke dalam VPS (tidak membuat user Linux, tidak instal paket, tidak SSH).

**Monitoring**
6. Log aktivitas terbatas pada **metadata sesi** (waktu konek/putus, IP, volume data). Isi komunikasi tidak direkam dan tidak dianalisis.
7. Tidak ada *deep packet inspection*, dengan alasan: trafik terenkripsi, keterbatasan perangkat, dan pertimbangan privasi (UU PDP).
8. Ketersediaan VPS diukur dengan **ICMP echo**, bukan pemeriksaan tingkat aplikasi. VPS yang membalas ping tetap mungkin punya layanan yang mati.
9. Data monitoring bersifat *near real-time* dengan interval polling tertentu, bukan streaming detik-per-detik.

**Fungsional**
10. Pemohon tidak memiliki akun login; identifikasi memakai nomor pengajuan.
11. Hanya ada satu peran admin; tidak ada hierarki persetujuan bertingkat.
12. Tidak ada modul pembayaran/billing.
13. Kredensial VPN disimpan terenkripsi (reversible), bukan hash, karena harus dapat ditampilkan ulang dan router menyimpannya secara reversible. Konsekuensi keamanannya diakui dan dimitigasi dengan enkripsi at-rest serta pencatatan audit setiap kali kredensial dilihat.
14. Pengujian dilakukan di lingkungan laboratorium dengan jumlah pengguna terbatas, bukan beban produksi.
15. Pengujian memakai MikroTik CHR (virtual), bukan perangkat fisik; angka throughput dipengaruhi batas lisensi CHR.

---

## 8. Metrik Evaluasi (Bab 4 skripsi) — WAJIB

**Risiko terbesar TA ini: dibaca sebagai "CRUD + wrapper API RouterOS".**
Kalau bab hasil isinya cuma screenshot dan tabel "fitur berhasil/tidak", nilai
akan biasa saja betapapun rapi kodenya. Pembedanya adalah **pengukuran**.

| Metrik | Cara ukur | Pembanding |
|---|---|---|
| Waktu provisioning | Stopwatch, >=30 percobaan | Manual via Winbox vs otomatis |
| Waktu deprovisioning kedaluwarsa | Selisih waktu expired -> benar-benar nonaktif | Manual (jam/hari) vs otomatis (menit) |
| Akurasi deteksi drift | Sengaja ubah N config manual, hitung berapa terdeteksi | — |
| **Konsistensi DB <-> router** | Jalankan N operasi admin (edit/disable/enable/hapus) acak, lalu bandingkan state DB dgn state router. Hitung % cocok | Manual vs otomatis |
| **Kelengkapan deprovision** | Setelah hapus/expire N akun, cek sisa yatim di router (secret, address-list, firewall rule) | Manual vs otomatis |
| Waktu diagnosa gangguan *(hanya jika 4.8B dibangun)* | 5-6 skenario gangguan buatan, ukur waktu sampai penyebab ditemukan | Manual vs fitur Diagnosa |
| Angka kesalahan | Salah ketik/salah konfigurasi per 30 percobaan | Manual vs otomatis |
| Usability | Kuesioner SUS ke beberapa admin/mahasiswa | — |

**Yang paling gampang dijual (dan paling sejalan dengan tema):** dua metrik
bertanda ** di atas. Jalankan puluhan operasi lifecycle acak, lalu buktikan
sistemmu menjaga DB dan router tetap identik 100%, sementara cara manual
selalu meninggalkan selisih. Itu grafik terbaik di skripsi — dan ia mengukur
tepat apa yang kamu klaim: **automated lifecycle yang konsisten**.

Metrik "sisa yatim" juga kuat: setelah hapus akun secara manual, hampir selalu
ada firewall rule atau address-list yang tertinggal. Sistemmu tidak.

---

## 9. Prioritas Pengerjaan

Kalau waktu mepet, **potong dari bawah**. Jangan potong dari atas.

```
STATUS PENGERJAAN diperiksa ulang 2026-09-05 malam. Seluruh butir WAJIB dan
NILAI+ selesai dan terverifikasi terhadap CHR RouterOS 7.23.5, kecuali satu
butir NILAI+ (grafik bandwidth).

SELESAI | Auth admin + review pengajuan (ACC/REJECT via detail)
        | CRUD VPS + ping terjadwal (flap protection 3x)
        | >> HAPUS VPS berantai -> deprovision semua akunnya (tema inti)
        | Provisioning otomatis ke MikroTik (CREATE)
        | >> EDIT akun -> job terenkripsi -> router          (tema inti)
        | >> DISABLE / ENABLE akun -> merambat ke router     (tema inti)
        | >> HAPUS akun -> deprovision penuh                 (tema inti)
        | Expiry otomatis + perpanjangan
        | Email queued: diterima, ACC+kredensial, ditolak, H-3
        | Lihat kredensial + panel Status Sinkronisasi (4.8 bagian A)
        | Log sesi (polling /ppp active tiap 30 detik)
        | Limit bandwidth (paket)
        | Audit log tindakan admin
        | Klien VPN tetap dapat internet (NAT masquerade + DNS)
-----------------------------------------------------
NILAI+  | Drift detection & rekonsiliasi push/pull  -> SELESAI
BELUM   | Grafik penggunaan bandwidth
-----------------------------------------------------
DICORET | Rantai diagnosa penuh (4.8 bagian B)  <- keputusan user 2026-09-05
        | Tombol "Putuskan Sesi" terpisah        <- sudah tercakup di Disable
-----------------------------------------------------
OPSIONAL| Log metadata trafik / NetFlow (Tingkat 2)
        | Notifikasi selain email
```

---

## 10. Aturan Kerja untuk Agent

1. **Baca dokumen ini penuh** sebelum menulis kode.
2. **Jangan ubah Keputusan Terkunci** (bagian 3) tanpa izin eksplisit user.
3. **Jangan rewrite frontend** yang sudah ada. Lanjutkan.
4. **Jangan tambah dependency** untuk hal yang bisa diselesaikan beberapa baris kode atau sudah disediakan Laravel/Vue.
5. **Semua operasi ke router lewat queue.** Tidak ada pengecualian.
6. **Verifikasi field RouterOS di CHR asli**, jangan percaya dokumentasi versi lain.
7. Kalau ada ambiguitas yang mengubah bentuk sistem, **tanya user**, jangan tebak.
8. Kalau menemukan keputusan baru yang penting, **update dokumen ini**.

---

## 10a. Perintah Artisan yang Tersedia

| Perintah | Fungsi | Jadwal |
|---|---|---|
| `router:cek [--ping=IP]` | Preflight kesiapan router | manual |
| `vpn:uji-siklus [--simpan]` | Demo siklus hidup penuh + ukur durasi | manual |
| `vpn:polling-sesi` | Selaraskan log sesi dengan `/ppp active` | tiap 30 detik |
| `vps:ping` | Ketersediaan VPS dari sisi router | tiap 5 menit |
| `vpn:sinkron [--daftar]` | Deteksi drift DB vs router | tiap 10 menit |
| `vpn:kedaluwarsa [--dry-run]` | H-3, kedaluwarsa, pembersihan H+30 | harian 01:00 |
| `vpn:pasang-ulang [--dry-run] [--paksa]` | Bangun ulang objek seluruh akun di router dari basis data | manual |

Penjadwalan didefinisikan di `routes/console.php`. Semuanya memakai
`withoutOverlapping()` supaya eksekusi tidak bertumpuk ketika router lambat
merespons — tanpa itu, polling 30 detik bisa saling menyusul.

Jalankan penjadwal dengan satu proses: `php artisan schedule:work`.

### 10a-1. Mengganti router

Ketika router diganti atau dikembalikan ke keadaan kosong, basis data tetap
memegang seluruh akun sementara router tidak punya apa-apa. Ini persis kondisi
drift `hilang_di_router` pada skala penuh, dan `vpn:pasang-ulang` yang
menyelesaikannya.

**Urutannya:**

1. Siapkan fondasi router dengan `chr-setup.rsc`. Sertifikat, service, pengguna
   API, IP pool, PPP profile, dan aturan firewall dasar **tidak** dibuat oleh
   sistem (batasan #3).
2. Arahkan `ROUTEROS_BASE_URL` di `.env` ke alamat router baru, dan sesuaikan
   `alamat_server_vpn` di menu Pengaturan.
3. `php artisan router:cek` sampai seluruh prasyarat OK.
4. `php artisan vpn:pasang-ulang --dry-run` untuk melihat daftar akun yang akan
   dibangun ulang.
5. `php artisan vpn:pasang-ulang` untuk mengerjakannya.
6. `php artisan vpn:sinkron` untuk membuktikan hasilnya nol temuan.

**Kenapa ikatan `.id` harus dilepas lebih dulu.** Kolom `router_secret_id`,
`router_addresslist_id`, dan `router_firewall_id` menyimpan nomor urut internal
RouterOS seperti `*8`. Nomor itu **hanya bermakna pada router yang
menerbitkannya**. Di router baru, `*8` tetap ada tetapi milik objek lain,
kemungkinan besar salah satu aturan bawaan `chr-setup.rsc`.

Bila tidak dilepas, rantai kegagalannya begini:

```
perbaiki() memeriksa objekAda('ip/firewall/filter', '*8')  -> ADA
  -> sistem menyimpulkan aturan akun sudah terpasang
  -> aturan izin akun TIDAK PERNAH dibuat
  -> drift menandainya nilai_beda karena src-address tidak cocok
  -> push memanggil perbaiki() lagi -> kembali ke baris pertama
```

Temuan itu tidak akan pernah bisa diselesaikan. Karena itu `vpn:pasang-ulang`
menghapus objek lama (bila ada) lalu mengosongkan ketiga kolom sebelum membangun
ulang -- urutan ini diverifikasi 2026-09-07 terhadap CHR yang sekadar dinyalakan
ulang (bukan diganti): objek lama masih ada dengan nama sama, dan tanpa dihapus
lebih dulu, pembuatan objek baru akan ditolak RouterOS lalu meninggalkan .id
kosong di basis data padahal objek lama tetap hidup tak terlacak.

Pengujian yang sama juga menemukan `perbaiki()` tidak menyertakan `disabled`
saat membuat ulang secret dari nol, sehingga akun berstatus dinonaktifkan bisa
kembali aktif di router setelah pasang-ulang. Sudah diperbaiki; verifikasi wajib
`php artisan vpn:sinkron` menghasilkan `temuan=0` setelah pasang-ulang.

**Yang diperiksa sebelum mengerjakan apa pun:** IP pool, PPP profile untuk
setiap paket aktif, dan **aturan firewall tolak default**. Yang terakhir paling
penting: ia adalah tempat aturan izin tiap akun disisipkan. Tanpa aturan itu,
akun tetap terpasang tetapi isolasinya tidak berlaku sama sekali dan tidak ada
gejala yang terlihat. Berhenti lebih baik daripada menghasilkan sistem yang
tampak benar tetapi diam-diam terbuka.

**Akun berstatus `gagal_provision` ditangani dengan `provision()`**, bukan
`perbaiki()`, karena statusnya perlu ikut berpindah menjadi aktif. `perbaiki()`
sengaja tidak menyentuh status (lihat 11.10b).

> Kemampuan membangun ulang router dari basis data adalah peragaan terkuat dari
> pola *desired state reconciliation* yang diklaim penelitian ini: basis data
> memegang kebenaran, router disamakan dengannya.

## 10b. Catatan Pemasangan

Langkah pemasangan lingkungan, kredensial pengembangan, galat yang ditemui
beserta perbaikannya, dan cara reproduksi di perangkat lain dicatat di
**`SETUP.md`** (folder yang sama). Perbarui berkas itu setiap kali ada langkah
pemasangan baru atau kredensial berubah.

## 11. Skema Database

10 tabel (termasuk `users` bawaan Laravel). Konfigurasi koneksi router
disimpan di `.env`, **bukan tabel** — sistem hanya mengelola satu router
(batasan #2), jadi tabel pengaturan tidak diperlukan.

### 11.1 `users` — admin

Bawaan Laravel apa adanya. Satu peran (keputusan #8), tidak ada kolom role.
Tidak ada registrasi publik; akun dibuat lewat seeder/command.

### 11.2 `vps`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| nama | string(64) index | mis. `VPS-APP-01` — keunikan divalidasi di aplikasi, sama seperti `alamat_ip` |
| alamat_ip | string(45) unique | target ping & aturan firewall |
| keterangan | text null | |
| aktif | boolean default true | tampil/tidak di form pengajuan publik |
| status_terakhir | enum(up, down, unknown) default unknown | **denormalisasi** |
| rtt_terakhir_ms | decimal(6,2) null | denormalisasi |
| gagal_berturut | tinyint default 0 | flap protection, ambang 3 (4.4) |
| dicek_pada | timestamp null | |
| sedang_dihapus | boolean default false | penghapusan berantai sedang berjalan (4.4b) |
| timestamps, softDeletes | | |

> **`nama` dan `alamat_ip` harus unik hanya di antara baris yang BELUM dihapus.**
>
> MySQL/MariaDB **tidak mendukung** partial unique index
> (`WHERE deleted_at IS NULL`) — itu fitur PostgreSQL/SQLite. Unique gabungan
> `(alamat_ip, deleted_at)` juga tidak menyelamatkan, karena MySQL menganggap
> setiap `NULL` sebagai nilai berbeda sehingga dua VPS aktif dengan IP sama
> tetap lolos.
>
> **Cara yang dipakai:** index biasa (non-unique) pada `alamat_ip`, ditambah
> validasi di tingkat aplikasi:
>
> ```php
> Rule::unique('vps', 'alamat_ip')->whereNull('deleted_at')->ignore($vps)
> ```
>
> Berlaku sama untuk `nama`. Tanpa ini, VPS yang sudah di-soft-delete tetap
> "memesan" nama dan IP-nya selamanya sehingga VPS baru tidak bisa memakainya.
>
> Ini cukup: hanya admin yang membuat VPS dan jumlahnya sedikit, jadi risiko
> race condition praktis nol. `ponytail: validasi aplikasi, bukan constraint
> DB — naikkan ke partial index bila suatu saat pindah ke PostgreSQL.`

> Empat kolom denormalisasi itu disengaja: halaman list VPS cukup membaca
> baris `vps` tanpa subquery ke tabel riwayat. Ini yang bikin list-nya instan.

### 11.3 `vps_health_checks` — riwayat ping

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| vps_id | FK vps | |
| sumber | enum(router, laravel) | `router` = utama, `laravel` = pembanding (4.4) |
| status | enum(up, down) | |
| rtt_avg_ms | decimal(6,2) null | |
| packet_loss | tinyint | persen |
| pesan_error | text null | |
| checked_at | timestamp | |

Index: `(vps_id, checked_at)`. Pruning data > 90 hari.

### 11.4 `paket_bandwidth`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| nama | string(32) unique | Dasar / Standar / Prioritas |
| rx_rate | string(16) | mis. `2M` |
| tx_rate | string(16) | mis. `2M` |
| ppp_profile | string(64) | nama profile di RouterOS |
| keterangan | string(160) null | |
| aktif | boolean default true | |
| timestamps | | |

Nilai acuan keputusan #7: Dasar 2M/2M, Standar 5M/5M, Prioritas 10M/10M.

> **KEPUTUSAN USER 2026-09-10: paket bandwidth TIDAK di-seed dan TIDAK
> dikonfigurasi di `chr-setup.rsc`.** Admin menambah tiap paket lewat menu
> Pengaturan di web sejak awal; `PaketBandwidthController::store` memicu
> `SelaraskanPaketJob` yang membuat PPP profile-nya di router (rate-limit,
> local-address, remote-address=pool, dns-server). `DatabaseSeeder` hanya
> memanggil `AdminSeeder`. `PaketBandwidthSeeder` tetap ada untuk mengisi tiga
> contoh secara manual bila perlu:
> `php artisan db:seed --class=Database\Seeders\PaketBandwidthSeeder`.
> L2TP server di `chr-setup.rsc` memakai `default-profile=default-encryption`
> (profile bawaan RouterOS), bukan `vpn-standar`.

### 11.5 `pengajuan`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| nomor | string(24) unique | `VPN-YYYY-NNNN` |
| jenis | enum(baru, perpanjangan) default baru | keputusan #9 |
| akun_vpn_id | FK akun_vpn null | diisi hanya bila jenis = perpanjangan |
| nama | string(120) | |
| identitas | string(64) | NIP/NIK |
| instansi | string(160) | |
| email | string(160) | tujuan pengiriman kredensial |
| vps_id | FK vps | |
| keperluan | string(120) | |
| keperluan_detail | text null | diisi bila keperluan = "Lainnya" |
| durasi_mulai | date | |
| durasi_selesai | date | **pemicu fase 7** |
| status | enum(diajukan, ditinjau, disetujui, ditolak) | |
| alasan_penolakan | text null | wajib bila ditolak, masuk email |
| ditinjau_oleh | FK users null | |
| ditinjau_pada | timestamp null | |
| timestamps | | |

> **PERBAIKAN dari kode frontend:** `generateNomorPengajuan()` di
> `data/pengajuan.js` memakai `array.length + 1`. Itu **race condition** —
> dua pengajuan bersamaan akan dapat nomor sama. Di backend, pembuatan nomor
> WAJIB di dalam transaksi DB dengan penguncian baris, dan kolom `nomor`
> diberi unique constraint sebagai jaring pengaman terakhir.

### 11.6 `akun_vpn` — tabel inti

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| pengajuan_id | FK pengajuan unique | |
| vps_id | FK vps | VPS yang boleh diakses |
| paket_bandwidth_id | FK paket_bandwidth | |
| username | string(64) unique | = nama `/ppp secret` |
| password | text | cast **`encrypted`** (4.5, batasan #13) |
| ip_vpn | string(45) unique null | `remote-address` tetap; dasar address-list & firewall |
| status | enum(...) | lihat state machine §2 |
| mulai_pada | date | |
| selesai_pada | date | |
| router_secret_id | string(24) null | `.id` RouterOS, mis. `*1A` |
| router_addresslist_id | string(24) null | |
| router_firewall_id | string(24) null | |
| disinkron_pada | timestamp null | terakhir dipastikan cocok dgn router |
| pesan_error | text null | diisi saat status = gagal_provision |
| peringatan_h3_dikirim_pada | timestamp null | cegah email H-3 terkirim ganda |
| alasan_penghapusan | enum(admin, kedaluwarsa, vps_dihapus) null | diisi saat status = dihapus |
| dibuat_oleh / diubah_oleh | FK users null | |
| timestamps, softDeletes | | |

Nilai `status`: `menunggu_provision`, `provisioning`, `aktif`,
`dinonaktifkan`, `gagal_provision`, `akan_kedaluwarsa`, `kedaluwarsa`,
`dihapus`.

> **Kenapa tiga kolom `router_*_id`:** operasi edit/disable/hapus tidak boleh
> bergantung pada pencarian by-name di router — rapuh, dan langsung rusak
> begitu username diedit (4.5a). Menyimpan `.id` RouterOS membuat job
> **idempotent**: bisa dijalankan ulang tanpa efek ganda, dan tahu persis
> objek mana yang harus dihapus.
>
> **`ip_vpn` wajib tetap per akun.** Isolasi firewall (keputusan #3) mustahil
> tanpa IP yang deterministik — address-list dan aturan filter dibangun di
> atas IP ini.
>
> **Jangan hard delete.** Soft delete, supaya riwayat sesi dan jejak audit
> tetap utuh untuk Bab 4 (4.5d).

### 11.7 `sesi_vpn` — log sesi

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| akun_vpn_id | FK akun_vpn | |
| username_snapshot | string(64) | **salinan** username saat sesi terjadi |
| ip_vpn | string(45) | |
| ip_asal | string(45) null | `caller-id`, IP publik client |
| mulai_pada | timestamp | |
| selesai_pada | timestamp null | null = masih aktif |
| durasi_detik | int null | diisi saat sesi berakhir |
| bytes_in / bytes_out | bigint default 0 | |
| aktif | boolean default true | |
| timestamps | | |

Index: `(akun_vpn_id, mulai_pada)`, `(aktif)`.
Sumber: polling `/ppp active` tiap 30 detik (4.6).

> `username_snapshot` bukan duplikasi sia-sia: kalau admin mengedit username
> (4.5a), riwayat sesi lama harus tetap menunjukkan username yang berlaku
> saat itu. Tanpa ini, laporan riwayatmu berbohong setelah edit pertama.

Uptime dihitung dari tabel ini:
- *Uptime sesi berjalan* = `now() - mulai_pada` pada baris `aktif = true`
- *Total durasi pemakaian* = `SUM(durasi_detik)` seluruh sesi akun

### 11.8 `operasi_router` — buku besar operasi

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| jenis | enum(provision, edit, disable, enable, hapus, hapus_vps, expire, extend, ping, sinkron) | |
| akun_vpn_id | FK null | |
| vps_id | FK null | |
| status | enum(antre, berjalan, sukses, gagal) | |
| payload | json null | perintah yang dikirim |
| hasil | json null | balasan router |
| pesan_error | text null | |
| percobaan | tinyint default 0 | |
| dimulai_pada / selesai_pada | timestamp null | |
| durasi_ms | int null | |
| dipicu_oleh | FK users null | null = scheduler |
| timestamps | | |

> **TABEL INI ADALAH SUMBER DATA BAB 4.** `durasi_ms` per `jenis` memberi
> waktu provisioning/edit/hapus **terukur otomatis**, bukan hasil stopwatch
> manual. Rasio `sukses:gagal` memberi angka keandalan. Bangun tabel ini
> sejak awal — kalau menyusul di akhir, kamu kehilangan seluruh data
> percobaan awal.

### 11.9 `drift` — temuan ketidaksesuaian

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| akun_vpn_id | FK null | |
| vps_id | FK null | |
| jenis_objek | enum(ppp_secret, address_list, firewall_rule) | |
| atribut | string(64) null | mis. `rate-limit`, `disabled` |
| jenis_drift | enum(nilai_beda, hilang_di_router, yatim_di_router) | |
| nilai_db | text null | |
| nilai_router | text null | |
| status | enum(terbuka, diselesaikan, diabaikan) | |
| resolusi | enum(push, pull) null | push = router ikut DB; pull = DB ikut router |
| terdeteksi_pada | timestamp | |
| diselesaikan_pada | timestamp null | |
| diselesaikan_oleh | FK users null | |

> `yatim_di_router` menangkap sisa dari penghapusan yang gagal separuh jalan
> (4.5d) — objek yang masih ada di router tapi tidak punya pasangan di DB.
> Ini juga metrik "kelengkapan deprovision" di §8.

### 11.10 `audit_log`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| user_id | FK users null | |
| aksi | string(64) | |
| objek_tipe | string(64) null | |
| objek_id | bigint null | |
| deskripsi | string(255) | |
| data_lama / data_baru | json null | |
| ip_admin | string(45) | |
| user_agent | string(255) null | |
| created_at | timestamp | |

Aksi yang **wajib** tercatat: `login`, `acc_pengajuan`, `tolak_pengajuan`,
CRUD VPS, `hapus_vps_berantai`, `edit_akun`, `disable_akun`, `enable_akun`, `hapus_akun`,
`putus_sesi`, `resolusi_drift`, dan — paling penting — **`lihat_kredensial`**
(keputusan #12, mitigasi batasan #13).

### 11.9b Lapisan email

| Mailable | Dipicu oleh |
|---|---|
| `PengajuanDiterima` | `POST /api/pengajuan` **dan** `POST /api/perpanjangan` (jenis dibedakan lewat `$pengajuan->jenis` di template) |
| `PengajuanDitolak` | admin menolak pengajuan (berlaku untuk kedua jenis) |
| `KredensialVpn` | **`ProvisionAkunJob` setelah provisioning SUKSES** |
| `PerpanjanganDisetujui` | **`OperasiAkunJob` (jenis `extend`) setelah `perpanjang()` SUKSES di router** |
| `PeringatanKedaluwarsa` | `vpn:kedaluwarsa` pada H-3 |

Seluruhnya `ShouldQueue` — kegagalan SMTP tidak boleh membuat operasi yang
sudah berhasil tampak gagal.

> **KEPUTUSAN USER 2026-09-11: alur perpanjangan sebelumnya sama sekali tidak
> mengirim email** — submit tidak dikabari (`PerpanjanganController::store()`
> tidak memanggil `Mail::to()` sama sekali), dan ACC admin juga diam
> (`OperasiAkunJob` jenis `extend` tidak punya efek email apa pun). Pemohon
> hanya tahu hasilnya kalau membuka halaman cek status sendiri. Ditutup dengan
> mengirim `PengajuanDiterima` di submit (reuse, bukan mailable baru) dan
> mailable baru `PerpanjanganDisetujui` setelah ACC berhasil di router — pola
> yang sama dengan `KredensialVpn`: jangan kabari pemohon sebelum perubahan
> benar-benar berlaku, karena operasi router bisa gagal.

> **`KredensialVpn` dikirim setelah objek benar-benar terpasang di router**,
> bukan saat pengajuan disetujui. Mengirim lebih awal berarti pemohon menerima
> kredensial yang belum tentu dapat dipakai.
>
> **`ROUTEROS_VPN_SERVER` berbeda dari `ROUTEROS_BASE_URL`.** Base URL adalah
> jalur manajemen (host-only) yang hanya dapat dijangkau server Laravel;
> pemohon terhubung lewat alamat LAN/publik. Mengirim alamat manajemen membuat
> koneksi klien selalu gagal.
>
> **KEPUTUSAN USER 2026-09-10: `alamat_server_vpn` dan `rentang_pool_vpn`
> dapat diubah admin lewat menu Pengaturan (tabel `pengaturan`).** `.env`
> (`ROUTEROS_VPN_SERVER`, `ROUTEROS_POOL_RANGE`) hanya jadi nilai bawaan.
> `ProvisionAkunJob` membaca `Pengaturan::ambil('alamat_server_vpn')` untuk
> email kredensial; `AlokasiIp::dariConfig()` membaca `rentang_pool_vpn`.
> Alasan: alamat LAN router dari DHCP bisa berubah, dan mengunci di `.env`
> berarti tiap perubahan menuntut edit berkas + restart.

> **KEPUTUSAN USER 2026-09-11: reklaim IP manual, bukan otomatis.** `AlokasiIp`
> tetap tidak pernah daur ulang IP sendiri (alasan keamanan tidak berubah, lihat
> komentar di kelas itu). Yang ditambahkan: panel "Reklaim IP" di tab Server VPN
> (menu Pengaturan) menampilkan akun terhapus yang IP-nya masih tercatat
> terpakai, diurut dari yang paling lama dihapus. Tombol reklaim (set `ip_vpn`
> jadi `null` di baris akun yang sudah soft-delete, dicatat di `audit_log` aksi
> `reklaim_ip`) hanya aktif kalau akun itu tidak punya temuan `drift` berstatus
> `terbuka` — jadi admin yang memutuskan secara eksplisit, bukan sistem yang
> nge-reuse diam-diam. Endpoint: `GET/POST admin/pengaturan/ip-reklaim[/{id}]`
> di `PengaturanController`.

### 11.10b Lapisan layanan

| Kelas | Tanggung jawab |
|---|---|
| `RouterOs\RouterOsClient` | Pembungkus REST API. Tidak menyentuh basis data, bisa diuji tanpa router |
| `Vpn\AlokasiIp` | Memilih alamat VPN berikutnya. IP **tidak pernah** didaur ulang otomatis (lihat reklaim manual di bawah) |
| `Vpn\PenerbitAkun` | Menerbitkan baris `akun_vpn` dari pengajuan disetujui |
| `Vpn\NomorPengajuan` | Menerbitkan `VPN-YYYY-XX99` (2 huruf + 2 angka acak, sengaja tidak berurutan), retry sampai unik |
| `Vpn\ProvisioningService` | Seluruh operasi siklus hidup ke router |
| `Vpn\PencatatSesi` | Menyelaraskan log sesi dengan `/ppp active` |
| `Vpn\SinkronisasiService` | Deteksi drift dan rekonsiliasi push/pull |
| `PencatatAudit` | Jejak tindakan admin |

Method publik `ProvisioningService`: `provision`, `perbaiki`, `nonaktifkan`,
`aktifkan`, `hapus`, `kedaluwarsakan`, `perpanjang`, `putusSesi`.

> **`perbaiki()` berbeda dari `provision()` dan keduanya harus tetap ada.**
> `provision()` menjalankan transisi status (menunggu → provisioning → aktif).
> `perbaiki()` mengembalikan objek router agar sesuai basis data **tanpa
> menyentuh status** — dipakai rekonsiliasi drift arah push. Akun yang sudah
> aktif tidak boleh dipaksa kembali ke status provisioning hanya untuk
> memperbaiki satu atribut.

### 11.11 Lapisan model

- **`App\Enums\StatusAkun`** — state machine akun sebagai backed enum.
  Transisi yang sah didefinisikan **hanya di sini**; setiap perpindahan status
  wajib lewat `bisaPindahKe()` supaya tidak ada job/controller yang memindahkan
  status sembarangan. Dua helper dipakai job sinkron drift:
  `seharusnyaEnabledDiRouter()` dan `seharusnyaAdaDiRouter()`.
  Diuji di `tests/Unit/StatusAkunTest.php`.
- **`AkunVpn::$hidden = ['password']`** — password tidak pernah ikut
  serialisasi otomatis. Menampilkannya harus eksplisit DAN dicatat audit
  (`lihat_kredensial`).
- **`Pengajuan::$fillable`** dibatasi ketat: `nomor`, `status`, dan seluruh
  kolom peninjauan TIDAK fillable karena model ini menerima input dari
  endpoint publik tanpa autentikasi.
- **`Vps::scopePublik()`** — `aktif = true` DAN `sedang_dihapus = false`.
  Wajib dipakai endpoint publik.
- **`AkunVpn::scopeAdaDiRouter()`** — status selain `menunggu_provision` dan
  `dihapus`. Dipakai job sinkron untuk membedakan drift 'hilang' dari 'yatim'.

### 11.11b Catatan penerapan yang mudah terlewat

1. **Nilai default kolom TIDAK termuat setelah `create()`.** Objek hasil
   `create()` tidak membaca ulang default dari basis data, sehingga response
   API berisi `null`. Nyatakan di `$attributes` model (sudah dilakukan pada
   `Vps`) atau isi eksplisit (sudah dilakukan pada `AkunVpn::status`).
2. **`AlokasiIp` dan `RouterOsClient` tidak bisa di-autowire** karena
   konstruktornya menerima nilai dari config. Binding-nya didaftarkan di
   `AppServiceProvider::register()`.
3. **Aplikasi ini murni API, tanpa halaman login Laravel.** `bootstrap/app.php`
   memasang `redirectGuestsTo(fn () => null)`; tanpa itu permintaan tanpa token
   melempar 500 (`Route [login] not defined`) alih-alih 401.

### 11.12 Ringkasan relasi

```
users ──< pengajuan (ditinjau_oleh)
users ──< audit_log, operasi_router, drift

vps ──< pengajuan
vps ──< akun_vpn
vps ──< vps_health_checks

paket_bandwidth ──< akun_vpn

pengajuan ──1:1── akun_vpn
akun_vpn ──< sesi_vpn
akun_vpn ──< operasi_router
akun_vpn ──< drift
akun_vpn ──< pengajuan (perpanjangan; self-reference via akun_vpn_id)
```

## Preferensi pengguna 2026-09-06

- Semua teks antarmuka tanpa em dash.
- Semua antarmuka admin dan publik menggunakan keluarga font utama yang sama, termasuk angka, IP, dan kredensial.
- Kerjakan fitur satu per satu; konfirmasi keputusan yang mengubah lingkup kepada pengguna.
- Edit akun tahap ini meliputi username, password, paket bandwidth, dan satu VPS tujuan sesuai model yang ada.
